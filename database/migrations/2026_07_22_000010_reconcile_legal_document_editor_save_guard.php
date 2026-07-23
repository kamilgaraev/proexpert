<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = (string) DB::selectOne('SELECT current_schema() name')->name;
        if (preg_match('/^[a-z_][a-z0-9_]*$/D', $schema) !== 1) {
            throw new RuntimeException('legal_document_editor_schema_name_invalid');
        }

        $this->assertTriggerDescriptor();
        $actual = $this->functionDescriptor();

        if ($this->matchesDescriptor($actual, $this->desiredBody(), $schema)) {
            return;
        }

        if (! $this->matchesDescriptor($actual, $this->legacyBody(), $schema)) {
            throw new RuntimeException('legal_document_editor_save_guard_descriptor_mismatch');
        }

        DB::unprepared("CREATE OR REPLACE FUNCTION legal_document_editor_save_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog, \"{$schema}\" AS \$fn\$\n{$this->desiredBody()};\n\$fn\$");

        if (! $this->matchesDescriptor($this->functionDescriptor(), $this->desiredBody(), $schema)) {
            throw new RuntimeException('legal_document_editor_save_guard_descriptor_mismatch');
        }

        $this->assertTriggerDescriptor();
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_editor_save_guard_reconciliation_forward_only');
    }

    private function functionDescriptor(): ?object
    {
        return DB::selectOne(<<<'SQL'
SELECT p.prosrc body,p.provolatile volatility,p.prosecdef::integer security_definer,p.proconfig configuration,
       p.proparallel parallel_safety,p.proleakproof::integer leakproof,p.prokind function_kind,
       pg_get_function_result(p.oid) result,pg_get_function_identity_arguments(p.oid) arguments,l.lanname language
FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace JOIN pg_language l ON l.oid=p.prolang
WHERE n.nspname=current_schema() AND p.proname='legal_document_editor_save_guard'
  AND pg_get_function_identity_arguments(p.oid)=''
SQL);
    }

    private function assertTriggerDescriptor(): void
    {
        $actual = DB::select(<<<'SQL'
SELECT t.relname table_name,p.proname function_name,g.tgtype,g.tgenabled,g.tgisinternal::integer internal,
       octet_length(g.tgargs) argument_bytes,(g.tgqual IS NOT NULL)::integer has_when,g.tgconstraint
FROM pg_trigger g JOIN pg_class t ON t.oid=g.tgrelid JOIN pg_namespace n ON n.oid=t.relnamespace
JOIN pg_proc p ON p.oid=g.tgfoid
WHERE n.nspname=current_schema() AND g.tgname='legal_document_editor_save_immutable'
SQL);

        if (count($actual) !== 1) {
            throw new RuntimeException('legal_document_editor_save_trigger_descriptor_mismatch');
        }

        $trigger = $actual[0];
        if ($trigger->table_name !== 'legal_document_editor_saves'
            || $trigger->function_name !== 'legal_document_editor_save_guard' || (int) $trigger->tgtype !== 31
            || $trigger->tgenabled !== 'O' || (bool) $trigger->internal || (int) $trigger->argument_bytes !== 0
            || (bool) $trigger->has_when || (int) $trigger->tgconstraint !== 0) {
            throw new RuntimeException('legal_document_editor_save_trigger_descriptor_mismatch');
        }
    }

    private function matchesDescriptor(?object $actual, string $expectedBody, string $schema): bool
    {
        if ($actual === null) {
            return false;
        }

        $configuration = trim(str_replace('"', '', implode(',', (array) $actual->configuration)), '{}');

        return $this->normalizeBody((string) $actual->body) === $this->normalizeBody($expectedBody)
            && $actual->volatility === 'v' && ! (bool) $actual->security_definer && $actual->result === 'trigger'
            && $actual->arguments === '' && $actual->language === 'plpgsql' && $actual->parallel_safety === 'u'
            && ! (bool) $actual->leakproof && $actual->function_kind === 'f'
            && $configuration === "search_path=pg_catalog, {$schema}";
    }

    private function desiredBody(): string
    {
        return <<<'PLPGSQL'
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'legal_document_editor_save_delete_forbidden'; END IF;
 IF TG_OP='INSERT' THEN
   PERFORM 1 FROM legal_document_editor_sessions s WHERE s.id=NEW.editor_session_id FOR UPDATE;
   IF NOT FOUND THEN RAISE EXCEPTION 'legal_document_editor_session_not_found'; END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_sessions s
      WHERE s.id=NEW.editor_session_id AND s.final_generation IS NOT NULL) THEN
     RAISE EXCEPTION 'legal_document_editor_save_after_terminal';
   END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_sessions s
      WHERE s.id=NEW.editor_session_id AND NEW.save_generation <= s.last_applied_generation) THEN
     RAISE EXCEPTION 'legal_document_editor_save_generation_stale';
   END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_saves s
      WHERE s.editor_session_id=NEW.editor_session_id AND s.terminal
        AND s.state IN ('reserved','processing','completed')) THEN
     RAISE EXCEPTION 'legal_document_editor_save_after_terminal';
   END IF;
   IF NEW.supersedes_save_id IS NULL AND EXISTS (SELECT 1 FROM legal_document_editor_saves s
      WHERE s.editor_session_id=NEW.editor_session_id AND s.terminal AND s.state='failed') THEN
     RAISE EXCEPTION 'legal_document_editor_save_supersession_invalid';
   END IF;
   IF NEW.supersedes_save_id IS NOT NULL AND (NOT NEW.terminal OR NOT EXISTS (
      SELECT 1 FROM legal_document_editor_saves s
       WHERE s.id=NEW.supersedes_save_id AND s.editor_session_id=NEW.editor_session_id
         AND s.terminal AND s.state='failed' AND s.save_generation<NEW.save_generation
         AND s.save_generation=(SELECT max(t.save_generation) FROM legal_document_editor_saves t
           WHERE t.editor_session_id=NEW.editor_session_id AND t.terminal AND t.state='failed')
   )) THEN
     RAISE EXCEPTION 'legal_document_editor_save_supersession_invalid';
   END IF;
   RETURN NEW;
 END IF;
 IF (OLD.organization_id,OLD.document_id,OLD.editor_session_id,OLD.source_version_id,OLD.document_file_id,
     OLD.save_generation,OLD.callback_status,OLD.replay_hash,OLD.supersedes_save_id,OLD.operation_id,OLD.terminal,OLD.created_at)
    IS DISTINCT FROM
    (NEW.organization_id,NEW.document_id,NEW.editor_session_id,NEW.source_version_id,NEW.document_file_id,
     NEW.save_generation,NEW.callback_status,NEW.replay_hash,NEW.supersedes_save_id,NEW.operation_id,NEW.terminal,NEW.created_at) THEN
   RAISE EXCEPTION 'legal_document_editor_save_identity_immutable';
 END IF;
 IF OLD.state='completed' AND OLD IS DISTINCT FROM NEW THEN RAISE EXCEPTION 'legal_document_editor_save_terminal_immutable'; END IF;
 IF OLD.saved_version_id IS NOT NULL AND OLD.saved_version_id IS DISTINCT FROM NEW.saved_version_id THEN
   RAISE EXCEPTION 'legal_document_editor_save_result_immutable';
 END IF;
 IF NOT ((OLD.state='reserved' AND NEW.state IN ('reserved','processing','completed','failed'))
   OR (OLD.state='processing' AND NEW.state IN ('processing','reserved','completed','failed'))
   OR (OLD.state='failed' AND NEW.state='failed') OR NEW.state=OLD.state) THEN
   RAISE EXCEPTION 'legal_document_editor_save_transition_forbidden';
 END IF;
 IF OLD.state='reserved' AND NEW.state='completed' AND NEW.callback_status<>4 THEN
   RAISE EXCEPTION 'legal_document_editor_save_transition_forbidden';
 END IF;
 IF NEW.state IN ('processing','completed') AND OLD.state IS DISTINCT FROM NEW.state THEN
   PERFORM 1 FROM legal_document_editor_sessions s WHERE s.id=NEW.editor_session_id FOR UPDATE;
   IF NOT FOUND THEN RAISE EXCEPTION 'legal_document_editor_session_not_found'; END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_sessions s
      WHERE s.id=NEW.editor_session_id AND s.final_generation IS NOT NULL) THEN
     RAISE EXCEPTION 'legal_document_editor_save_after_terminal';
   END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_sessions s
      WHERE s.id=NEW.editor_session_id AND NEW.save_generation <= s.last_applied_generation) THEN
     RAISE EXCEPTION 'legal_document_editor_save_generation_stale';
   END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_saves s
      WHERE s.editor_session_id=NEW.editor_session_id AND s.id<>NEW.id AND s.terminal
        AND s.save_generation < NEW.save_generation) THEN
     RAISE EXCEPTION 'legal_document_editor_save_after_terminal';
   END IF;
 END IF;
 RETURN NEW;
END
PLPGSQL;
    }

    private function legacyBody(): string
    {
        return <<<'PLPGSQL'
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'legal_document_editor_save_delete_forbidden'; END IF;
 IF TG_OP='INSERT' THEN
   PERFORM 1 FROM legal_document_editor_sessions s WHERE s.id=NEW.editor_session_id FOR UPDATE;
   IF NOT FOUND THEN RAISE EXCEPTION 'legal_document_editor_session_not_found'; END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_sessions s
      WHERE s.id=NEW.editor_session_id AND s.final_generation IS NOT NULL) THEN
     RAISE EXCEPTION 'legal_document_editor_save_after_terminal';
   END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_sessions s
      WHERE s.id=NEW.editor_session_id AND NEW.save_generation <= s.last_applied_generation) THEN
     RAISE EXCEPTION 'legal_document_editor_save_generation_stale';
   END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_saves s
      WHERE s.editor_session_id=NEW.editor_session_id AND s.terminal) THEN
     RAISE EXCEPTION 'legal_document_editor_save_after_terminal';
   END IF;
   RETURN NEW;
 END IF;
 IF (OLD.organization_id,OLD.document_id,OLD.editor_session_id,OLD.source_version_id,OLD.document_file_id,
     OLD.save_generation,OLD.callback_status,OLD.replay_hash,OLD.operation_id,OLD.terminal,OLD.created_at)
    IS DISTINCT FROM
    (NEW.organization_id,NEW.document_id,NEW.editor_session_id,NEW.source_version_id,NEW.document_file_id,
     NEW.save_generation,NEW.callback_status,NEW.replay_hash,NEW.operation_id,NEW.terminal,NEW.created_at) THEN
   RAISE EXCEPTION 'legal_document_editor_save_identity_immutable';
 END IF;
 IF OLD.state='completed' AND OLD IS DISTINCT FROM NEW THEN RAISE EXCEPTION 'legal_document_editor_save_terminal_immutable'; END IF;
 IF OLD.saved_version_id IS NOT NULL AND OLD.saved_version_id IS DISTINCT FROM NEW.saved_version_id THEN
   RAISE EXCEPTION 'legal_document_editor_save_result_immutable';
 END IF;
 IF NOT ((OLD.state='reserved' AND NEW.state IN ('reserved','processing','failed'))
   OR (OLD.state='processing' AND NEW.state IN ('processing','reserved','completed','failed'))
   OR (OLD.state='failed' AND NEW.state IN ('failed','processing')) OR NEW.state=OLD.state) THEN
   RAISE EXCEPTION 'legal_document_editor_save_transition_forbidden';
 END IF;
 IF NEW.state IN ('processing','completed') AND OLD.state IS DISTINCT FROM NEW.state THEN
   PERFORM 1 FROM legal_document_editor_sessions s WHERE s.id=NEW.editor_session_id FOR UPDATE;
   IF NOT FOUND THEN RAISE EXCEPTION 'legal_document_editor_session_not_found'; END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_sessions s
      WHERE s.id=NEW.editor_session_id AND s.final_generation IS NOT NULL) THEN
     RAISE EXCEPTION 'legal_document_editor_save_after_terminal';
   END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_sessions s
      WHERE s.id=NEW.editor_session_id AND NEW.save_generation <= s.last_applied_generation) THEN
     RAISE EXCEPTION 'legal_document_editor_save_generation_stale';
   END IF;
   IF EXISTS (SELECT 1 FROM legal_document_editor_saves s
      WHERE s.editor_session_id=NEW.editor_session_id AND s.id<>NEW.id AND s.terminal
        AND s.save_generation < NEW.save_generation) THEN
     RAISE EXCEPTION 'legal_document_editor_save_after_terminal';
   END IF;
 END IF;
 RETURN NEW;
END
PLPGSQL;
    }

    private function normalizeBody(string $body): string
    {
        return (string) preg_replace('/[\s;]+/', '', strtolower(trim($body)));
    }
};
