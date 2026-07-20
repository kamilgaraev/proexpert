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
        $indexMigration = require __DIR__.'/2026_07_19_000710_create_legal_document_editor_session_indexes.php';
        $indexMigration->assertIndexManifest();
        foreach ($this->constraints() as $table => $constraints) {
            foreach ($constraints as $name => $definition) {
                $actual = DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(c.oid,true) definition
FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace
WHERE n.nspname=current_schema() AND t.relname=? AND c.conname=?
SQL, [$table, $name]);
                if ($actual === null) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
                } elseif ($this->normalizeConstraint((string) $actual->definition)
                    !== $this->normalizeConstraint(str_replace(' NOT VALID', '', $definition))) {
                    throw new RuntimeException("legal_document_editor_constraint_descriptor_mismatch:{$name}");
                }
            }
        }
        $this->installGuards();
        $this->assertInstalledManifest();
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_editor_session_constraints_forward_only');
    }

    private function constraints(): array
    {
        return [
            'legal_document_editor_sessions' => [
                'legal_editor_sessions_status_check' => "CHECK (status IN ('active','processing','completed','expired','failed','closed')) NOT VALID",
                'legal_editor_sessions_mode_check' => "CHECK (mode IN ('edit','review')) NOT VALID",
                'legal_editor_sessions_generation_check' => 'CHECK (generation > 0 AND next_save_generation > last_applied_generation AND last_applied_generation >= 0 AND (final_generation IS NULL OR (final_generation = last_applied_generation AND final_generation > 0))) NOT VALID',
                'legal_editor_sessions_hash_check' => "CHECK (source_content_hash ~ '^[a-f0-9]{64}$') NOT VALID",
                'legal_editor_sessions_state_check' => "CHECK ((status IN ('active','processing') AND completed_at IS NULL AND final_generation IS NULL) OR (status='completed' AND saved_version_id IS NOT NULL AND completed_at IS NOT NULL AND final_generation IS NOT NULL) OR (status='closed' AND completed_at IS NOT NULL) OR (status IN ('expired','failed') AND completed_at IS NOT NULL AND final_generation IS NULL)) NOT VALID",
                'legal_editor_sessions_time_check' => 'CHECK (expires_at > created_at AND (completed_at IS NULL OR completed_at >= created_at)) NOT VALID',
                'legal_editor_sessions_document_fk' => 'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents(id, organization_id) ON DELETE RESTRICT NOT VALID',
                'legal_editor_sessions_source_version_fk' => 'FOREIGN KEY (source_version_id, document_file_id, document_id, organization_id) REFERENCES legal_archive_document_versions(id, document_file_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID',
                'legal_editor_sessions_saved_version_fk' => 'FOREIGN KEY (saved_version_id, document_file_id, document_id, organization_id) REFERENCES legal_archive_document_versions(id, document_file_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID',
                'legal_editor_sessions_actor_fk' => 'FOREIGN KEY (opened_by_user_id) REFERENCES users(id) ON DELETE RESTRICT NOT VALID',
            ],
            'legal_document_editor_participants' => [
                'legal_editor_participants_session_fk' => 'FOREIGN KEY (editor_session_id, organization_id) REFERENCES legal_document_editor_sessions(id, organization_id) ON DELETE RESTRICT NOT VALID',
                'legal_editor_participants_user_fk' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT NOT VALID',
                'legal_editor_participants_actor_key_check' => "CHECK (actor_key ~ '^[a-f0-9]{64}$') NOT VALID",
                'legal_editor_participants_ability_check' => "CHECK (required_ability IN ('view','comment','edit')) NOT VALID",
                'legal_editor_participants_time_check' => 'CHECK (joined_at <= created_at AND created_at <= updated_at) NOT VALID',
            ],
            'legal_document_editor_saves' => [
                'legal_editor_saves_session_fk' => 'FOREIGN KEY (editor_session_id, organization_id, document_id, source_version_id, document_file_id) REFERENCES legal_document_editor_sessions(id, organization_id, document_id, source_version_id, document_file_id) ON DELETE RESTRICT NOT VALID',
                'legal_editor_saves_source_version_fk' => 'FOREIGN KEY (source_version_id, document_file_id, document_id, organization_id) REFERENCES legal_archive_document_versions(id, document_file_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID',
                'legal_editor_saves_saved_version_fk' => 'FOREIGN KEY (saved_version_id, document_file_id, document_id, organization_id) REFERENCES legal_archive_document_versions(id, document_file_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID',
                'legal_editor_saves_generation_check' => 'CHECK (save_generation > 0) NOT VALID',
                'legal_editor_saves_callback_check' => 'CHECK (callback_status IN (2,4,6) AND terminal = (callback_status IN (2,4))) NOT VALID',
                'legal_editor_saves_state_check' => "CHECK (state IN ('reserved','processing','completed','failed')) NOT VALID",
                'legal_editor_saves_hash_check' => "CHECK (replay_hash ~ '^[a-f0-9]{64}$' AND (lease_owner_hash IS NULL OR lease_owner_hash ~ '^[a-f0-9]{64}$') AND (content_hash IS NULL OR content_hash ~ '^[a-f0-9]{64}$')) NOT VALID",
                'legal_editor_saves_lease_check' => "CHECK ((state='processing' AND lease_owner_hash IS NOT NULL AND lease_expires_at IS NOT NULL AND completed_at IS NULL AND failed_at IS NULL) OR (state='reserved' AND lease_owner_hash IS NULL AND lease_expires_at IS NULL AND completed_at IS NULL AND failed_at IS NULL) OR (state='completed' AND lease_owner_hash IS NULL AND lease_expires_at IS NULL AND completed_at IS NOT NULL AND failed_at IS NULL) OR (state='failed' AND lease_owner_hash IS NULL AND lease_expires_at IS NULL AND completed_at IS NULL AND failed_at IS NOT NULL)) NOT VALID",
                'legal_editor_saves_result_check' => "CHECK ((state='completed' AND callback_status IN (2,6) AND saved_version_id IS NOT NULL AND content_hash IS NOT NULL) OR (state='completed' AND callback_status=4 AND saved_version_id IS NULL AND content_hash IS NULL) OR (state<>'completed' AND saved_version_id IS NULL AND content_hash IS NULL)) NOT VALID",
                'legal_editor_saves_time_check' => 'CHECK ((completed_at IS NULL OR completed_at >= created_at) AND (failed_at IS NULL OR failed_at >= created_at)) NOT VALID',
            ],
        ];
    }

    private function installGuards(): void
    {
        $schema = (string) DB::selectOne('SELECT current_schema() name')->name;
        if (preg_match('/^[a-z_][a-z0-9_]*$/D', $schema) !== 1) {
            throw new RuntimeException('legal_document_editor_schema_name_invalid');
        }
        $functions = $this->guardFunctions();
        foreach ($functions as $name => $body) {
            $this->assertFunctionPredecessor($name, $body, $schema);
            DB::unprepared("CREATE OR REPLACE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog, \"{$schema}\" AS \$fn\$\n{$body};\n\$fn\$");
        }
        DB::unprepared(<<<SQL
CREATE OR REPLACE TRIGGER legal_document_editor_session_immutable
BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}".legal_document_editor_sessions
FOR EACH ROW EXECUTE FUNCTION legal_document_editor_session_guard();
CREATE OR REPLACE TRIGGER legal_document_editor_participant_immutable
BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}".legal_document_editor_participants
FOR EACH ROW EXECUTE FUNCTION legal_document_editor_participant_guard();
CREATE OR REPLACE TRIGGER legal_document_editor_save_immutable
BEFORE INSERT OR UPDATE OR DELETE ON "{$schema}".legal_document_editor_saves
FOR EACH ROW EXECUTE FUNCTION legal_document_editor_save_guard();
CREATE OR REPLACE TRIGGER legal_document_editor_save_apply_generation
AFTER UPDATE ON "{$schema}".legal_document_editor_saves
FOR EACH ROW EXECUTE FUNCTION legal_document_editor_save_apply_guard();
SQL);
    }

    private function guardFunctions(): array
    {
        return [
            'legal_document_editor_session_guard' => <<<'PLPGSQL'
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'legal_document_editor_session_delete_forbidden'; END IF;
 IF TG_OP='INSERT' THEN RETURN NEW; END IF;
 IF (OLD.organization_id,OLD.document_id,OLD.source_version_id,OLD.document_file_id,OLD.opened_by_user_id,
     OLD.provider,OLD.mode,OLD.generation,OLD.document_key,OLD.source_content_hash,OLD.expires_at,OLD.created_at)
    IS DISTINCT FROM
    (NEW.organization_id,NEW.document_id,NEW.source_version_id,NEW.document_file_id,NEW.opened_by_user_id,
     NEW.provider,NEW.mode,NEW.generation,NEW.document_key,NEW.source_content_hash,NEW.expires_at,NEW.created_at) THEN
   RAISE EXCEPTION 'legal_document_editor_session_identity_immutable';
 END IF;
 IF NEW.next_save_generation < OLD.next_save_generation OR NEW.next_save_generation > OLD.next_save_generation+1
    OR NEW.next_save_generation <= NEW.last_applied_generation THEN
   RAISE EXCEPTION 'legal_document_editor_session_generation_invalid';
 END IF;
 IF NEW.last_applied_generation < OLD.last_applied_generation
    OR (OLD.final_generation IS NOT NULL AND NEW.final_generation IS DISTINCT FROM OLD.final_generation) THEN
   RAISE EXCEPTION 'legal_document_editor_session_generation_invalid';
 END IF;
 IF NEW.last_applied_generation IS DISTINCT FROM OLD.last_applied_generation
    AND NOT EXISTS (SELECT 1 FROM legal_document_editor_saves s
      WHERE s.editor_session_id=NEW.id AND s.save_generation=NEW.last_applied_generation AND s.state='completed') THEN
   RAISE EXCEPTION 'legal_document_editor_session_generation_unbacked';
 END IF;
 IF OLD.status IN ('completed','expired','failed','closed') AND OLD IS DISTINCT FROM NEW THEN
   RAISE EXCEPTION 'legal_document_editor_session_terminal_immutable';
 END IF;
 IF NOT ((OLD.status='active' AND NEW.status IN ('active','processing','completed','expired','closed'))
   OR (OLD.status='processing' AND NEW.status IN ('active','processing','completed','failed','expired','closed'))
   OR NEW.status=OLD.status) THEN RAISE EXCEPTION 'legal_document_editor_session_transition_forbidden'; END IF;
 RETURN NEW;
END
PLPGSQL,
            'legal_document_editor_participant_guard' => <<<'PLPGSQL'
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'legal_document_editor_participant_immutable'; END IF;
 RETURN NEW;
END
PLPGSQL,
            'legal_document_editor_save_guard' => <<<'PLPGSQL'
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
PLPGSQL,
            'legal_document_editor_save_apply_guard' => <<<'PLPGSQL'
BEGIN
 IF OLD.state IS DISTINCT FROM 'completed' AND NEW.state='completed' THEN
   UPDATE legal_document_editor_sessions
      SET last_applied_generation=NEW.save_generation,
          final_generation=CASE WHEN NEW.terminal THEN NEW.save_generation ELSE NULL END,
          saved_version_id=COALESCE(NEW.saved_version_id,saved_version_id),
          status=CASE WHEN NEW.callback_status=2 THEN 'completed' WHEN NEW.callback_status=4 THEN 'closed' ELSE status END,
          completed_at=CASE WHEN NEW.terminal THEN NEW.completed_at ELSE NULL END,
          updated_at=NEW.updated_at
    WHERE id=NEW.editor_session_id;
   UPDATE legal_document_editor_saves
      SET state='failed',lease_owner_hash=NULL,lease_expires_at=NULL,failed_at=NEW.completed_at,updated_at=NEW.updated_at
    WHERE editor_session_id=NEW.editor_session_id AND id<>NEW.id AND state IN ('reserved','processing')
      AND (NEW.terminal OR save_generation < NEW.save_generation);
 END IF;
 RETURN NULL;
END
PLPGSQL,
        ];
    }

    private function assertFunctionPredecessor(string $name, string $expectedBody, string $schema): void
    {
        $actual = DB::selectOne(<<<'SQL'
SELECT p.prosrc body,p.provolatile volatility,p.prosecdef::integer security_definer,p.proconfig configuration,
       p.proparallel parallel_safety,p.proleakproof::integer leakproof,p.prokind function_kind,
       pg_get_function_result(p.oid) result,pg_get_function_identity_arguments(p.oid) arguments,l.lanname language
FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace JOIN pg_language l ON l.oid=p.prolang
WHERE n.nspname=current_schema() AND p.proname=? AND pg_get_function_identity_arguments(p.oid)=''
SQL, [$name]);
        if ($actual === null) {
            return;
        }
        $configuration = str_replace('"', '', implode(',', (array) $actual->configuration));
        if ($this->normalizeBody((string) $actual->body) !== $this->normalizeBody($expectedBody)
            || $actual->volatility !== 'v' || (bool) $actual->security_definer || $actual->result !== 'trigger'
            || $actual->arguments !== '' || $actual->language !== 'plpgsql'
            || $actual->parallel_safety !== 'u' || (bool) $actual->leakproof || $actual->function_kind !== 'f'
            || ! str_contains($configuration, "search_path=pg_catalog, {$schema}")) {
            throw new RuntimeException("legal_document_editor_function_descriptor_mismatch:{$name}");
        }
    }

    public function assertInstalledManifest(): void
    {
        $schema = (string) DB::selectOne('SELECT current_schema() name')->name;
        foreach ($this->constraints() as $table => $constraints) {
            $actualNames = array_map(static fn (object $row): string => (string) $row->name, DB::select(<<<'SQL'
SELECT c.conname name FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace
WHERE n.nspname=current_schema() AND t.relname=? ORDER BY c.conname
SQL, [$table]));
            $expectedNames = array_merge([$table.'_pkey'], array_keys($constraints));
            sort($expectedNames);
            if ($actualNames !== $expectedNames) {
                throw new RuntimeException("legal_document_editor_constraint_set_mismatch:{$table}");
            }
            foreach ($constraints as $name => $definition) {
                $actual = DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(c.oid,true) definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid
JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname=? AND c.conname=?
SQL, [$table, $name]);
                if ($actual === null || $this->normalizeConstraint((string) $actual->definition)
                    !== $this->normalizeConstraint(str_replace(' NOT VALID', '', $definition))) {
                    throw new RuntimeException("legal_document_editor_constraint_manifest_mismatch:{$name}");
                }
            }
        }
        foreach ($this->guardFunctions() as $name => $body) {
            $this->assertFunctionPredecessor($name, $body, $schema);
        }
        $expected = [
            'legal_document_editor_session_immutable' => ['legal_document_editor_sessions', 'legal_document_editor_session_guard', 31],
            'legal_document_editor_participant_immutable' => ['legal_document_editor_participants', 'legal_document_editor_participant_guard', 31],
            'legal_document_editor_save_immutable' => ['legal_document_editor_saves', 'legal_document_editor_save_guard', 31],
            'legal_document_editor_save_apply_generation' => ['legal_document_editor_saves', 'legal_document_editor_save_apply_guard', 17],
        ];
        foreach ($expected as $name => [$table, $function, $type]) {
            $actual = DB::selectOne(<<<'SQL'
SELECT t.relname table_name,p.proname function_name,g.tgtype,g.tgenabled,g.tgisinternal::integer internal,
       octet_length(g.tgargs) argument_bytes,(g.tgqual IS NOT NULL)::integer has_when,g.tgconstraint
FROM pg_trigger g JOIN pg_class t ON t.oid=g.tgrelid JOIN pg_namespace n ON n.oid=t.relnamespace
JOIN pg_proc p ON p.oid=g.tgfoid WHERE n.nspname=current_schema() AND g.tgname=?
SQL, [$name]);
            if ($actual === null || $actual->table_name !== $table || $actual->function_name !== $function
                || (int) $actual->tgtype !== $type || $actual->tgenabled !== 'O' || (bool) $actual->internal
                || (int) $actual->argument_bytes !== 0 || (bool) $actual->has_when || (int) $actual->tgconstraint !== 0) {
                throw new RuntimeException("legal_document_editor_trigger_descriptor_mismatch:{$name}");
            }
        }
    }

    private function normalizeConstraint(string $value): string
    {
        $value = strtolower(str_replace(['"', 'public.', 'not valid'], '', $value));
        $value = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $value);
        $value = (string) preg_replace('/[\s()]+/', '', $value);

        return str_replace(['=anyarray[', ']'], ['in', ''], $value);
    }

    private function normalizeBody(string $body): string
    {
        return (string) preg_replace('/[\s;]+/', '', strtolower(trim($body)));
    }
};
