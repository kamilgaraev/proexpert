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
        $constraints = [
            'legal_editor_sessions_status_check' => "CHECK (status IN ('active','processing','completed','expired','failed','closed')) NOT VALID",
            'legal_editor_sessions_mode_check' => "CHECK (mode IN ('edit','review')) NOT VALID",
            'legal_editor_sessions_generation_check' => 'CHECK (generation > 0) NOT VALID',
            'legal_editor_sessions_hash_check' => "CHECK (source_content_hash ~ '^[a-f0-9]{64}$') NOT VALID",
            'legal_editor_sessions_replay_hash_check' => "CHECK (callback_replay_hash IS NULL OR callback_replay_hash ~ '^[a-f0-9]{64}$') NOT VALID",
            'legal_editor_sessions_state_check' => "CHECK ((status='completed' AND saved_version_id IS NOT NULL AND completed_at IS NOT NULL) OR (status<>'completed' AND saved_version_id IS NULL)) NOT VALID",
            'legal_editor_sessions_document_fk' => 'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents(id, organization_id) ON DELETE RESTRICT NOT VALID',
            'legal_editor_sessions_source_version_fk' => 'FOREIGN KEY (source_version_id, document_id, organization_id) REFERENCES legal_archive_document_versions(id, document_id, organization_id) ON DELETE RESTRICT NOT VALID',
            'legal_editor_sessions_file_fk' => 'FOREIGN KEY (document_file_id, document_id, organization_id) REFERENCES legal_archive_document_files(id, document_id, organization_id) ON DELETE RESTRICT NOT VALID',
            'legal_editor_sessions_saved_version_fk' => 'FOREIGN KEY (saved_version_id, document_id, organization_id) REFERENCES legal_archive_document_versions(id, document_id, organization_id) ON DELETE RESTRICT NOT VALID',
            'legal_editor_sessions_actor_fk' => 'FOREIGN KEY (opened_by_user_id) REFERENCES users(id) ON DELETE RESTRICT NOT VALID',
        ];
        foreach ($constraints as $name => $definition) {
            $actual = DB::selectOne("SELECT pg_get_constraintdef(c.oid, true) definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname='legal_document_editor_sessions' AND c.conname=?", [$name]);
            if ($actual === null) {
                DB::statement("ALTER TABLE legal_document_editor_sessions ADD CONSTRAINT {$name} {$definition}");
            } elseif ($this->normalize((string) $actual->definition) !== $this->normalize(str_replace(' NOT VALID', '', $definition))) {
                throw new RuntimeException("legal_document_editor_constraint_descriptor_mismatch:{$name}");
            }
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION legal_document_editor_session_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $fn$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'legal_document_editor_session_delete_forbidden'; END IF;
 IF OLD.organization_id<>NEW.organization_id OR OLD.document_id<>NEW.document_id
  OR OLD.source_version_id<>NEW.source_version_id OR OLD.document_file_id<>NEW.document_file_id
  OR OLD.opened_by_user_id<>NEW.opened_by_user_id OR OLD.provider<>NEW.provider
  OR OLD.mode<>NEW.mode OR OLD.generation<>NEW.generation OR OLD.document_key<>NEW.document_key
  OR OLD.source_content_hash<>NEW.source_content_hash OR OLD.expires_at<>NEW.expires_at THEN
  RAISE EXCEPTION 'legal_document_editor_session_identity_immutable';
 END IF;
 IF OLD.callback_replay_hash IS NOT NULL AND OLD.callback_replay_hash IS DISTINCT FROM NEW.callback_replay_hash THEN
  RAISE EXCEPTION 'legal_document_editor_session_replay_immutable';
 END IF;
 IF OLD.saved_version_id IS DISTINCT FROM NEW.saved_version_id
  AND NOT (OLD.status='processing' AND NEW.status='completed' AND OLD.saved_version_id IS NULL AND NEW.saved_version_id IS NOT NULL) THEN
  RAISE EXCEPTION 'legal_document_editor_session_saved_version_immutable';
 END IF;
 IF OLD.status IN ('completed','expired','failed','closed') AND
  (OLD.status, OLD.callback_replay_hash, OLD.callback_lease_token_hash, OLD.callback_lease_expires_at,
   OLD.callback_attempt_count, OLD.saved_version_id, OLD.completed_at, OLD.failure_code)
  IS DISTINCT FROM
  (NEW.status, NEW.callback_replay_hash, NEW.callback_lease_token_hash, NEW.callback_lease_expires_at,
   NEW.callback_attempt_count, NEW.saved_version_id, NEW.completed_at, NEW.failure_code) THEN
  RAISE EXCEPTION 'legal_document_editor_session_terminal_immutable';
 END IF;
 IF NOT ((OLD.status='active' AND NEW.status IN ('active','processing','expired','closed'))
  OR (OLD.status='processing' AND NEW.status IN ('active','processing','completed','failed','expired','closed'))
  OR (OLD.status IN ('completed','expired','failed','closed') AND NEW.status=OLD.status)) THEN
  RAISE EXCEPTION 'legal_document_editor_session_transition_forbidden';
 END IF;
 RETURN NEW;
END $fn$;
CREATE OR REPLACE TRIGGER legal_document_editor_session_immutable
BEFORE UPDATE OR DELETE ON public.legal_document_editor_sessions
FOR EACH ROW EXECUTE FUNCTION legal_document_editor_session_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_editor_session_constraints_forward_only');
    }

    private function normalize(string $value): string
    {
        $value = strtolower(str_replace(['"', 'public.', 'not valid'], '', $value));
        $value = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $value);
        $value = (string) preg_replace('/[\s()]+/', '', $value);

        return str_replace(['=anyarray[', ']'], ['in', ''], $value);
    }
};
