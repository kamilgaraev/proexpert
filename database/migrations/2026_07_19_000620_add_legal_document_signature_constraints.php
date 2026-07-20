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
        foreach ($this->constraints() as $name => [$table, $definition]) {
            $actual = DB::selectOne('SELECT c.conrelid::regclass::text AS table_name, pg_get_constraintdef(c.oid, true) AS definition, c.condeferrable::integer AS deferrable, c.condeferred::integer AS deferred FROM pg_constraint c JOIN pg_namespace n ON n.oid=(SELECT relnamespace FROM pg_class WHERE oid=c.conrelid) WHERE n.nspname=current_schema() AND c.conname=?', [$name]);
            if ($actual !== null) {
                if ($actual->table_name !== $table || $this->normalize((string) $actual->definition) !== $this->normalize(str_replace(' NOT VALID', '', $definition)) || (bool) $actual->deferrable || (bool) $actual->deferred) {
                    throw new RuntimeException("legal_signature_constraint_descriptor_mismatch:{$name}");
                }

                continue;
            }
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }
        $this->installGuards();
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_signature_migrations_are_forward_only');
    }

    private function constraints(): array
    {
        return [
            'legal_signature_requests_organization_fk' => ['legal_signature_requests', 'FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_requests_document_fk' => ['legal_signature_requests', 'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents (id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_requests_version_fk' => ['legal_signature_requests', 'FOREIGN KEY (document_version_id, document_id, organization_id) REFERENCES legal_archive_document_versions (id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_requests_party_fk' => ['legal_signature_requests', 'FOREIGN KEY (party_id, document_version_id, document_id, organization_id) REFERENCES legal_document_parties (id, document_version_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_requests_user_fk' => ['legal_signature_requests', 'FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_requests_method_check' => ['legal_signature_requests', "CHECK (method IN ('paper','external_electronic','provider_electronic')) NOT VALID"],
            'legal_signature_requests_status_check' => ['legal_signature_requests', "CHECK (status IN ('pending','completed','failed','revoked','expired')) NOT VALID"],
            'legal_signature_requests_hash_check' => ['legal_signature_requests', "CHECK (signed_content_hash ~ '^[a-f0-9]{64}$' AND correlation_id ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$' AND (callback_replay_hash IS NULL OR callback_replay_hash ~ '^[a-f0-9]{64}$') AND (callback_payload_hash IS NULL OR callback_payload_hash ~ '^[a-f0-9]{64}$')) NOT VALID"],
            'legal_signature_requests_signers_check' => ['legal_signature_requests', "CHECK (jsonb_typeof(signers) = 'array' AND jsonb_array_length(signers) > 0) NOT VALID"],
            'legal_signature_requests_provider_check' => ['legal_signature_requests', "CHECK ((method = 'paper' AND provider IS NULL) OR (method IN ('external_electronic','provider_electronic') AND NULLIF(btrim(provider), '') IS NOT NULL)) NOT VALID"],
            'legal_signature_requests_callback_check' => ['legal_signature_requests', 'CHECK ((callback_replay_hash IS NULL) = (callback_payload_hash IS NULL) AND (status = \'pending\' OR completed_at IS NOT NULL)) NOT VALID'],
            'legal_signature_requests_time_check' => ['legal_signature_requests', 'CHECK (expires_at IS NULL OR expires_at > requested_at) NOT VALID'],
            'legal_document_signatures_request_fk' => ['legal_document_signatures', 'FOREIGN KEY (signature_request_id, document_version_id, document_id, organization_id) REFERENCES legal_signature_requests (id, document_version_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_signatures_organization_fk' => ['legal_document_signatures', 'FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_signatures_version_fk' => ['legal_document_signatures', 'FOREIGN KEY (document_version_id, document_id, organization_id) REFERENCES legal_archive_document_versions (id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_signatures_party_fk' => ['legal_document_signatures', 'FOREIGN KEY (party_id, document_version_id, document_id, organization_id) REFERENCES legal_document_parties (id, document_version_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_signatures_user_fk' => ['legal_document_signatures', 'FOREIGN KEY (registered_by_user_id) REFERENCES users (id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_signatures_method_check' => ['legal_document_signatures', "CHECK (method IN ('paper','external_electronic','provider_electronic')) NOT VALID"],
            'legal_document_signatures_hash_check' => ['legal_document_signatures', "CHECK (signed_content_hash ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$' AND (signature_content_hash IS NULL OR signature_content_hash ~ '^[a-f0-9]{64}$')) NOT VALID"],
            'legal_document_signatures_evidence_check' => ['legal_document_signatures', "CHECK ((method = 'paper' AND provider IS NULL AND signature_path IS NULL AND signature_content_hash IS NULL AND NULLIF(btrim(storage_location), '') IS NOT NULL AND verification_status = 'registered') OR (method IN ('external_electronic','provider_electronic') AND NULLIF(btrim(provider), '') IS NOT NULL AND NULLIF(btrim(signature_path), '') IS NOT NULL AND signature_content_hash IS NOT NULL AND storage_location IS NULL AND jsonb_typeof(certificate_metadata) = 'object' AND certificate_metadata <> '{}'::jsonb AND verification_status IN ('verified','failed','revoked'))) NOT VALID"],
            'legal_document_signatures_revocation_check' => ['legal_document_signatures', "CHECK ((verification_status = 'revoked' AND NULLIF(btrim(revocation_reason), '') IS NOT NULL) OR (verification_status <> 'revoked' AND revocation_reason IS NULL)) NOT VALID"],
            'legal_document_signatures_time_check' => ['legal_document_signatures', 'CHECK (signed_at <= created_at AND (verified_at IS NULL OR verified_at >= signed_at)) NOT VALID'],
            'legal_signature_verifications_signature_fk' => ['legal_signature_verifications', 'FOREIGN KEY (signature_id, document_version_id, document_id, organization_id) REFERENCES legal_document_signatures (id, document_version_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_verifications_organization_fk' => ['legal_signature_verifications', 'FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_verifications_user_fk' => ['legal_signature_verifications', 'FOREIGN KEY (verified_by_user_id) REFERENCES users (id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_verifications_status_check' => ['legal_signature_verifications', "CHECK (status IN ('verified','failed','revoked')) NOT VALID"],
            'legal_signature_verifications_hash_check' => ['legal_signature_verifications', "CHECK (signed_content_hash ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$') NOT VALID"],
            'legal_signature_verifications_revocation_check' => ['legal_signature_verifications', "CHECK ((status = 'revoked' AND NULLIF(btrim(revocation_reason), '') IS NOT NULL) OR (status <> 'revoked' AND revocation_reason IS NULL)) NOT VALID"],
        ];
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION legal_signature_append_only_guard() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'legal_signature_evidence_immutable';
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS legal_document_signatures_immutable_guard ON legal_document_signatures;
CREATE TRIGGER legal_document_signatures_immutable_guard BEFORE UPDATE OR DELETE ON legal_document_signatures FOR EACH ROW EXECUTE FUNCTION legal_signature_append_only_guard();
DROP TRIGGER IF EXISTS legal_signature_verifications_immutable_guard ON legal_signature_verifications;
CREATE TRIGGER legal_signature_verifications_immutable_guard BEFORE UPDATE OR DELETE ON legal_signature_verifications FOR EACH ROW EXECUTE FUNCTION legal_signature_append_only_guard();

CREATE OR REPLACE FUNCTION legal_signature_requests_mutation_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'legal_signature_request_delete_forbidden';
    END IF;
    IF current_setting('most.legal_signature_mutation', true) IS DISTINCT FROM 'service' THEN
        RAISE EXCEPTION 'legal_signature_request_update_forbidden';
    END IF;
    IF (OLD.organization_id, OLD.document_id, OLD.document_version_id, OLD.party_id, OLD.method,
        OLD.provider, OLD.signed_content_hash, OLD.signers, OLD.correlation_id, OLD.idempotency_key, OLD.request_hash,
        OLD.requested_by_user_id, OLD.requested_at, OLD.expires_at, OLD.created_at)
       IS DISTINCT FROM
       (NEW.organization_id, NEW.document_id, NEW.document_version_id, NEW.party_id, NEW.method,
        NEW.provider, NEW.signed_content_hash, NEW.signers, NEW.correlation_id, NEW.idempotency_key, NEW.request_hash,
        NEW.requested_by_user_id, NEW.requested_at, NEW.expires_at, NEW.created_at) THEN
        RAISE EXCEPTION 'legal_signature_request_identity_update_forbidden';
    END IF;
    IF NOT ((OLD.status = 'pending' AND NEW.status IN ('pending','completed','failed','revoked','expired')) OR OLD.status = NEW.status) THEN
        RAISE EXCEPTION 'legal_signature_request_transition_forbidden';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS legal_signature_requests_mutation_guard ON legal_signature_requests;
CREATE TRIGGER legal_signature_requests_mutation_guard BEFORE UPDATE OR DELETE ON legal_signature_requests FOR EACH ROW EXECUTE FUNCTION legal_signature_requests_mutation_guard();

CREATE OR REPLACE FUNCTION legal_archive_versions_immutable_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'legal_archive_version_delete_forbidden'; END IF;
    IF OLD.status = 'signed' THEN RAISE EXCEPTION 'legal_archive_signed_version_update_forbidden'; END IF;
    IF (OLD.document_id, OLD.document_file_id, OLD.organization_id, OLD.version_number, OLD.version_label,
        OLD.file_path, OLD.original_filename, OLD.mime_type, OLD.size_bytes, OLD.content_hash,
        OLD.metadata_hash, OLD.uploaded_by_user_id, OLD.uploaded_at, OLD.metadata, OLD.created_at)
       IS DISTINCT FROM
       (NEW.document_id, NEW.document_file_id, NEW.organization_id, NEW.version_number, NEW.version_label,
        NEW.file_path, NEW.original_filename, NEW.mime_type, NEW.size_bytes, NEW.content_hash,
        NEW.metadata_hash, NEW.uploaded_by_user_id, NEW.uploaded_at, NEW.metadata, NEW.created_at) THEN
        RAISE EXCEPTION 'legal_archive_version_content_update_forbidden';
    END IF;
    IF OLD.status IS DISTINCT FROM NEW.status THEN
        IF current_setting('most.legal_archive_version_mutation', true) IS DISTINCT FROM 'signature_service'
           OR NOT ((OLD.status NOT IN ('frozen','signed') AND NEW.status = 'frozen') OR (OLD.status = 'frozen' AND NEW.status = 'signed')) THEN
            RAISE EXCEPTION 'legal_archive_version_signature_transition_forbidden';
        END IF;
    END IF;
    IF OLD.processing_status IS DISTINCT FROM NEW.processing_status THEN
        IF current_setting('most.legal_archive_version_mutation', true) IS DISTINCT FROM 'service'
           OR NOT ((OLD.processing_status = 'quarantine' AND NEW.processing_status IN ('ready','failed')) OR (OLD.processing_status = 'failed' AND NEW.processing_status = 'quarantine')) THEN
            RAISE EXCEPTION 'legal_archive_version_transition_forbidden';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    private function normalize(string $definition): string
    {
        $definition = strtolower($definition);
        $definition = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $definition);

        return (string) preg_replace('/["()\s]+/', '', $definition);
    }
};
