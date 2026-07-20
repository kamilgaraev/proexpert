<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->indexes() as $name => $sql) {
            $actual = DB::selectOne('SELECT pg_get_indexdef(index_class.oid) AS definition, i.indisvalid::integer AS valid, i.indisready::integer AS ready, i.indislive::integer AS live FROM pg_class index_class JOIN pg_namespace n ON n.oid=index_class.relnamespace JOIN pg_index i ON i.indexrelid=index_class.oid WHERE n.nspname=current_schema() AND index_class.relname=?', [$name]);
            if ($actual !== null && (! (bool) $actual->valid || ! (bool) $actual->ready || ! (bool) $actual->live)) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
                $actual = null;
            }
            if ($actual !== null && $this->normalize((string) $actual->definition) !== $this->normalize(str_replace('CONCURRENTLY ', '', $sql))) {
                throw new RuntimeException("legal_signature_index_descriptor_mismatch:{$name}");
            }
            if ($actual === null) {
                DB::statement($sql);
            }
            $verified = DB::selectOne('SELECT pg_get_indexdef(index_class.oid) AS definition, i.indisvalid::integer AS valid, i.indisready::integer AS ready, i.indislive::integer AS live FROM pg_class index_class JOIN pg_namespace n ON n.oid=index_class.relnamespace JOIN pg_index i ON i.indexrelid=index_class.oid WHERE n.nspname=current_schema() AND index_class.relname=?', [$name]);
            if ($verified === null || ! (bool) $verified->valid || ! (bool) $verified->ready || ! (bool) $verified->live
                || $this->normalize((string) $verified->definition) !== $this->normalize(str_replace('CONCURRENTLY ', '', $sql))) {
                throw new RuntimeException("legal_signature_index_descriptor_mismatch:{$name}");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_signature_migrations_are_forward_only');
    }

    private function indexes(): array
    {
        return [
            'legal_document_parties_signature_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_document_parties_signature_ownership_unique ON legal_document_parties USING btree (id, document_version_id, document_id, organization_id)',
            'legal_document_versions_signature_hash_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_document_versions_signature_hash_unique ON legal_archive_document_versions USING btree (id, document_id, organization_id, content_hash)',
            'legal_signature_requests_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_signature_requests_ownership_unique ON legal_signature_requests USING btree (id, document_version_id, document_id, organization_id)',
            'legal_signature_requests_actor_idempotency_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_signature_requests_actor_idempotency_unique ON legal_signature_requests USING btree (organization_id, requested_by_user_id, idempotency_key)',
            'legal_signature_requests_correlation_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_signature_requests_correlation_unique ON legal_signature_requests USING btree (correlation_id)',
            'legal_signature_requests_provider_request_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_signature_requests_provider_request_unique ON legal_signature_requests USING btree (provider, provider_request_id) WHERE provider_request_id IS NOT NULL',
            'legal_signature_requests_callback_replay_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_signature_requests_callback_replay_unique ON legal_signature_requests USING btree (provider, callback_replay_hash) WHERE callback_replay_hash IS NOT NULL',
            'legal_signature_requests_pending_idx' => "CREATE INDEX CONCURRENTLY legal_signature_requests_pending_idx ON legal_signature_requests USING btree (organization_id, document_id, expires_at) WHERE status = 'pending'",
            'legal_signature_requests_replacement_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_signature_requests_replacement_unique ON legal_signature_requests USING btree (replaces_request_id) WHERE replaces_request_id IS NOT NULL',
            'legal_signature_requests_requirement_attempt_idx' => 'CREATE INDEX CONCURRENTLY legal_signature_requests_requirement_attempt_idx ON legal_signature_requests USING btree (organization_id, document_version_id, requirement_group_key, id)',
            'legal_document_signatures_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_document_signatures_ownership_unique ON legal_document_signatures USING btree (id, document_version_id, document_id, organization_id)',
            'legal_document_signatures_request_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_document_signatures_request_unique ON legal_document_signatures USING btree (signature_request_id)',
            'legal_document_signatures_version_idx' => 'CREATE INDEX CONCURRENTLY legal_document_signatures_version_idx ON legal_document_signatures USING btree (organization_id, document_version_id, signed_at)',
            'legal_signature_verifications_idempotency_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_signature_verifications_idempotency_unique ON legal_signature_verifications USING btree (signature_id, idempotency_key)',
            'legal_signature_verifications_signature_idx' => 'CREATE INDEX CONCURRENTLY legal_signature_verifications_signature_idx ON legal_signature_verifications USING btree (organization_id, signature_id, verified_at)',
            'legal_signature_provider_operations_provider_request_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_signature_provider_operations_provider_request_unique ON legal_signature_provider_operations USING btree (provider, provider_request_id) WHERE provider_request_id IS NOT NULL',
            'legal_signature_provider_operations_generation_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_signature_provider_operations_generation_unique ON legal_signature_provider_operations USING btree (signature_request_id, generation)',
            'legal_signature_provider_operations_lease_idx' => "CREATE INDEX CONCURRENTLY legal_signature_provider_operations_lease_idx ON legal_signature_provider_operations USING btree (status, lease_expires_at) WHERE status = 'starting'",
            'legal_signature_cleanup_debts_due_idx' => 'CREATE INDEX CONCURRENTLY legal_signature_cleanup_debts_due_idx ON legal_archive_file_cleanup_debts USING btree (next_attempt_at, id) WHERE resolved_at IS NULL AND dead_lettered_at IS NULL AND storage_version_id IS NOT NULL',
            'legal_archive_cleanup_debts_key_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_archive_cleanup_debts_key_unique ON legal_archive_file_cleanup_debts USING btree (organization_id, debt_key)',
        ];
    }

    private function normalize(string $sql): string
    {
        $sql = strtolower($sql);
        $sql = str_replace(['public.', '"'], '', $sql);
        $sql = str_replace(['(', ')', ';'], '', $sql);

        return (string) preg_replace('/\s+/', ' ', trim($sql));
    }
};
