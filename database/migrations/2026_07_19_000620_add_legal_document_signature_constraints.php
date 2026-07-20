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
        $this->extendAccessAbilities();
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
        $this->assertGuardDescriptors();
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
            'legal_signature_requests_version_fk' => ['legal_signature_requests', 'FOREIGN KEY (document_version_id, document_id, organization_id, signed_content_hash) REFERENCES legal_archive_document_versions (id, document_id, organization_id, content_hash) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_requests_party_fk' => ['legal_signature_requests', 'FOREIGN KEY (party_id, document_version_id, document_id, organization_id) REFERENCES legal_document_parties (id, document_version_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_requests_user_fk' => ['legal_signature_requests', 'FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_requests_method_check' => ['legal_signature_requests', "CHECK (method IN ('paper','external_electronic','provider_electronic')) NOT VALID"],
            'legal_signature_requests_status_check' => ['legal_signature_requests', "CHECK (status IN ('pending','completed','failed','revoked','expired')) NOT VALID"],
            'legal_signature_requests_hash_check' => ['legal_signature_requests', "CHECK (signed_content_hash ~ '^[a-f0-9]{64}$' AND signer_snapshot_hash ~ '^[a-f0-9]{64}$' AND requirement_snapshot_hash ~ '^[a-f0-9]{64}$' AND correlation_id ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$' AND (callback_replay_hash IS NULL OR callback_replay_hash ~ '^[a-f0-9]{64}$') AND (callback_payload_hash IS NULL OR callback_payload_hash ~ '^[a-f0-9]{64}$')) NOT VALID"],
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
            'legal_document_signatures_hash_check' => ['legal_document_signatures', "CHECK (signed_content_hash ~ '^[a-f0-9]{64}$' AND signer_snapshot_hash ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$' AND (signature_content_hash IS NULL OR signature_content_hash ~ '^[a-f0-9]{64}$') AND (certificate_fingerprint IS NULL OR certificate_fingerprint ~ '^[a-f0-9]{64}$') AND (client_ip_hash IS NULL OR client_ip_hash ~ '^[a-f0-9]{64}$') AND (user_agent_hash IS NULL OR user_agent_hash ~ '^[a-f0-9]{64}$')) NOT VALID"],
            'legal_document_signatures_evidence_check' => ['legal_document_signatures', "CHECK ((method = 'paper' AND provider IS NULL AND signature_path IS NULL AND signature_content_hash IS NULL AND NULLIF(btrim(storage_location), '') IS NOT NULL AND verification_status = 'registered') OR (method IN ('external_electronic','provider_electronic') AND NULLIF(btrim(provider), '') IS NOT NULL AND NULLIF(btrim(signature_path), '') IS NOT NULL AND signature_content_hash IS NOT NULL AND storage_location IS NULL AND jsonb_typeof(certificate_metadata) = 'object' AND certificate_metadata <> '{}'::jsonb AND verification_status IN ('verified','failed','revoked'))) NOT VALID"],
            'legal_document_signatures_revocation_check' => ['legal_document_signatures', "CHECK ((verification_status = 'revoked' AND NULLIF(btrim(revocation_reason), '') IS NOT NULL) OR (verification_status <> 'revoked' AND revocation_reason IS NULL)) NOT VALID"],
            'legal_document_signatures_time_check' => ['legal_document_signatures', 'CHECK (signed_at <= created_at AND (verified_at IS NULL OR verified_at >= signed_at)) NOT VALID'],
            'legal_document_signatures_typed_evidence_check' => ['legal_document_signatures', "CHECK (signature_kind IN ('paper_original','detached_cades','embedded_cades','xml_dsig') AND time_source IN ('provider','trusted_timestamp','certificate','operator') AND NULLIF(btrim(diagnostic_code), '') IS NOT NULL AND pg_column_size(certificate_metadata) <= 16384 AND pg_column_size(provider_metadata) <= 65536 AND ((method = 'paper' AND container_format IS NULL AND certificate_fingerprint IS NULL) OR (method <> 'paper' AND container_format IN ('p7s','p7m','sig','xml') AND certificate_fingerprint IS NOT NULL AND certificate_valid_from < certificate_valid_until AND signed_at BETWEEN certificate_valid_from AND certificate_valid_until AND storage_version_id IS NOT NULL AND detected_mime_type IS NOT NULL))) NOT VALID"],
            'legal_signature_verifications_signature_fk' => ['legal_signature_verifications', 'FOREIGN KEY (signature_id, document_version_id, document_id, organization_id) REFERENCES legal_document_signatures (id, document_version_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_verifications_organization_fk' => ['legal_signature_verifications', 'FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_verifications_user_fk' => ['legal_signature_verifications', 'FOREIGN KEY (verified_by_user_id) REFERENCES users (id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_verifications_status_check' => ['legal_signature_verifications', "CHECK (status IN ('verified','failed','revoked')) NOT VALID"],
            'legal_signature_verifications_hash_check' => ['legal_signature_verifications', "CHECK (signed_content_hash ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$') NOT VALID"],
            'legal_signature_verifications_revocation_check' => ['legal_signature_verifications', "CHECK ((status = 'revoked' AND NULLIF(btrim(revocation_reason), '') IS NOT NULL) OR (status <> 'revoked' AND revocation_reason IS NULL)) NOT VALID"],
            'legal_signature_provider_operations_request_fk' => ['legal_signature_provider_operations', 'FOREIGN KEY (signature_request_id, document_version_id, document_id, organization_id) REFERENCES legal_signature_requests (id, document_version_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_signature_provider_operations_status_check' => ['legal_signature_provider_operations', "CHECK (status IN ('starting','started','failed')) NOT VALID"],
            'legal_signature_provider_operations_hash_check' => ['legal_signature_provider_operations', "CHECK (correlation_id ~ '^[a-f0-9]{64}$' AND provider_idempotency_key ~ '^[a-f0-9]{64}$' AND (lease_token_hash IS NULL OR lease_token_hash ~ '^[a-f0-9]{64}$')) NOT VALID"],
            'legal_signature_provider_operations_lease_check' => ['legal_signature_provider_operations', "CHECK ((status = 'starting' AND lease_token_hash IS NOT NULL AND lease_expires_at IS NOT NULL) OR (status <> 'starting' AND lease_token_hash IS NULL AND lease_expires_at IS NULL)) NOT VALID"],
        ];
    }

    private function extendAccessAbilities(): void
    {
        $actual = DB::selectOne("SELECT pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname='legal_document_access_grants' AND c.conname='legal_document_access_abilities_check'");
        if ($actual === null) {
            throw new RuntimeException('legal_document_access_abilities_predecessor_missing');
        }
        $old = <<<'SQL'
CHECK (jsonb_typeof(abilities) = 'array'::text AND jsonb_array_length(abilities) > 0 AND abilities <@ '["view", "comment", "approve", "sign", "download", "manage"]'::jsonb AND (NOT abilities ? 'manage'::text OR subject_kind::text = 'internal_user'::text))
SQL;
        $new = <<<'SQL'
CHECK (jsonb_typeof(abilities) = 'array'::text AND jsonb_array_length(abilities) > 0 AND abilities <@ '["view", "comment", "approve", "request_signature", "sign", "verify_signature", "download", "manage"]'::jsonb AND (NOT abilities ? 'manage'::text OR subject_kind::text = 'internal_user'::text))
SQL;
        $normalized = $this->normalize((string) $actual->definition);
        if ($normalized === $this->normalize($new)) {
            return;
        }
        if ($normalized !== $this->normalize($old)) {
            throw new RuntimeException('legal_document_access_abilities_predecessor_mismatch');
        }
        DB::statement('ALTER TABLE legal_document_access_grants DROP CONSTRAINT legal_document_access_abilities_check');
        DB::statement("ALTER TABLE legal_document_access_grants ADD CONSTRAINT legal_document_access_abilities_check {$new} NOT VALID");
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION legal_signature_append_only_guard() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'legal_signature_evidence_immutable';
END;
$$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
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
        OLD.provider, OLD.signed_content_hash, OLD.signers, OLD.signer_snapshot_hash, OLD.profile_code,
        OLD.profile_lock_version, OLD.allowed_signature_kinds, OLD.required_signature_kinds, OLD.requirement_snapshot_hash,
        OLD.correlation_id, OLD.idempotency_key, OLD.request_hash,
        OLD.requested_by_user_id, OLD.requested_at, OLD.expires_at, OLD.created_at)
       IS DISTINCT FROM
       (NEW.organization_id, NEW.document_id, NEW.document_version_id, NEW.party_id, NEW.method,
        NEW.provider, NEW.signed_content_hash, NEW.signers, NEW.signer_snapshot_hash, NEW.profile_code,
        NEW.profile_lock_version, NEW.allowed_signature_kinds, NEW.required_signature_kinds, NEW.requirement_snapshot_hash,
        NEW.correlation_id, NEW.idempotency_key, NEW.request_hash,
        NEW.requested_by_user_id, NEW.requested_at, NEW.expires_at, NEW.created_at) THEN
        RAISE EXCEPTION 'legal_signature_request_identity_update_forbidden';
    END IF;
    IF NOT ((OLD.status = 'pending' AND NEW.status IN ('pending','completed','failed','revoked','expired')) OR OLD.status = NEW.status) THEN
        RAISE EXCEPTION 'legal_signature_request_transition_forbidden';
    END IF;
    IF OLD.status <> 'pending' AND OLD IS DISTINCT FROM NEW THEN
        RAISE EXCEPTION 'legal_signature_request_terminal_update_forbidden';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
DROP TRIGGER IF EXISTS legal_signature_requests_mutation_guard ON legal_signature_requests;
CREATE TRIGGER legal_signature_requests_mutation_guard BEFORE UPDATE OR DELETE ON legal_signature_requests FOR EACH ROW EXECUTE FUNCTION legal_signature_requests_mutation_guard();

CREATE OR REPLACE FUNCTION legal_signature_request_binding_guard() RETURNS trigger AS $$
DECLARE request_row legal_signature_requests%ROWTYPE;
BEGIN
    SELECT * INTO request_row FROM legal_signature_requests WHERE id = NEW.signature_request_id FOR KEY SHARE;
    IF NOT FOUND OR
       (request_row.organization_id, request_row.document_id, request_row.document_version_id,
        request_row.party_id, request_row.method, request_row.provider, request_row.signed_content_hash,
        request_row.signers, request_row.signer_snapshot_hash)
       IS DISTINCT FROM
       (NEW.organization_id, NEW.document_id, NEW.document_version_id,
        NEW.party_id, NEW.method, NEW.provider, NEW.signed_content_hash,
        NEW.signers, NEW.signer_snapshot_hash) THEN
        RAISE EXCEPTION 'legal_signature_request_binding_mismatch';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
DROP TRIGGER IF EXISTS legal_signature_request_binding_guard ON legal_document_signatures;
CREATE TRIGGER legal_signature_request_binding_guard BEFORE INSERT ON legal_document_signatures FOR EACH ROW EXECUTE FUNCTION legal_signature_request_binding_guard();

CREATE OR REPLACE FUNCTION legal_signature_provider_operation_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'legal_signature_provider_operation_delete_forbidden'; END IF;
    IF (OLD.organization_id, OLD.document_id, OLD.document_version_id, OLD.signature_request_id,
        OLD.provider, OLD.correlation_id, OLD.provider_idempotency_key, OLD.created_at)
       IS DISTINCT FROM
       (NEW.organization_id, NEW.document_id, NEW.document_version_id, NEW.signature_request_id,
        NEW.provider, NEW.correlation_id, NEW.provider_idempotency_key, NEW.created_at) THEN
        RAISE EXCEPTION 'legal_signature_provider_operation_identity_update_forbidden';
    END IF;
    IF NOT ((OLD.status IN ('starting','failed') AND NEW.status IN ('starting','started','failed')) OR OLD.status = NEW.status) THEN
        RAISE EXCEPTION 'legal_signature_provider_operation_transition_forbidden';
    END IF;
    IF OLD.status = 'started' AND OLD IS DISTINCT FROM NEW THEN
        RAISE EXCEPTION 'legal_signature_provider_operation_terminal_update_forbidden';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
DROP TRIGGER IF EXISTS legal_signature_provider_operation_guard ON legal_signature_provider_operations;
CREATE TRIGGER legal_signature_provider_operation_guard BEFORE UPDATE OR DELETE ON legal_signature_provider_operations FOR EACH ROW EXECUTE FUNCTION legal_signature_provider_operation_guard();

SQL);
    }

    private function normalize(string $definition): string
    {
        $definition = strtolower($definition);
        $definition = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $definition);

        return (string) preg_replace('/["()\s]+/', '', $definition);
    }

    private function assertGuardDescriptors(): void
    {
        $expected = [
            'legal_document_signatures_immutable_guard' => ['legal_document_signatures', 'legal_signature_append_only_guard', 'BEFORE UPDATE OR DELETE'],
            'legal_signature_verifications_immutable_guard' => ['legal_signature_verifications', 'legal_signature_append_only_guard', 'BEFORE UPDATE OR DELETE'],
            'legal_signature_requests_mutation_guard' => ['legal_signature_requests', 'legal_signature_requests_mutation_guard', 'BEFORE UPDATE OR DELETE'],
            'legal_signature_request_binding_guard' => ['legal_document_signatures', 'legal_signature_request_binding_guard', 'BEFORE INSERT'],
            'legal_signature_provider_operation_guard' => ['legal_signature_provider_operations', 'legal_signature_provider_operation_guard', 'BEFORE UPDATE OR DELETE'],
        ];
        foreach ($expected as $triggerName => [$table, $function, $event]) {
            $descriptor = DB::selectOne(<<<'SQL'
SELECT table_class.relname AS table_name,
       function_proc.proname AS function_name,
       pg_get_triggerdef(trigger_row.oid, true) AS trigger_definition,
       trigger_row.tgenabled AS enabled,
       function_proc.prosecdef::integer AS security_definer,
       function_proc.provolatile AS volatility,
       function_proc.proconfig AS configuration
FROM pg_trigger trigger_row
JOIN pg_class table_class ON table_class.oid = trigger_row.tgrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
JOIN pg_proc function_proc ON function_proc.oid = trigger_row.tgfoid
WHERE namespace.nspname = current_schema() AND trigger_row.tgname = ? AND NOT trigger_row.tgisinternal
SQL, [$triggerName]);
            $configuration = $descriptor === null ? [] : (array) ($descriptor->configuration ?? []);
            $hasSafeSearchPath = str_contains(implode(',', $configuration), 'search_path=pg_catalog, public');
            if ($descriptor === null || $descriptor->table_name !== $table || $descriptor->function_name !== $function
                || ! str_contains(strtoupper((string) $descriptor->trigger_definition), $event)
                || $descriptor->enabled !== 'O' || (bool) $descriptor->security_definer
                || $descriptor->volatility !== 'v' || ! $hasSafeSearchPath) {
                throw new RuntimeException("legal_signature_guard_descriptor_mismatch:{$triggerName}");
            }
        }
    }
};
