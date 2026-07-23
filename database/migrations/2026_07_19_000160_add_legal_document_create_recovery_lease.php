<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $existing = array_fill_keys(Schema::getColumnListing('legal_archive_documents'), true);
        Schema::table('legal_archive_documents', function (Blueprint $table) use ($existing): void {
            if (! isset($existing['create_operation_id'])) {
                $table->uuid('create_operation_id')->nullable();
            }
            if (! isset($existing['create_operation_key'])) {
                $table->string('create_operation_key', 191)->nullable();
            }
            if (! isset($existing['source_create_attempt_token'])) {
                $table->uuid('source_create_attempt_token')->nullable();
            }
            if (! isset($existing['source_create_attempt_count'])) {
                $table->unsignedInteger('source_create_attempt_count')->default(0);
            }
            if (! isset($existing['source_create_started_at'])) {
                $table->timestampTz('source_create_started_at')->nullable();
            }
            if (! isset($existing['source_create_heartbeat_at'])) {
                $table->timestampTz('source_create_heartbeat_at')->nullable();
            }
            if (! isset($existing['source_create_lease_expires_at'])) {
                $table->timestampTz('source_create_lease_expires_at')->nullable();
            }
            if (! isset($existing['source_create_retry_action'])) {
                $table->string('source_create_retry_action', 32)->nullable();
            }
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->indexDescriptors() as $name => $descriptor) {
            $actual = $this->indexDescriptor($name);
            if ($actual !== null && ! $this->sameIndex($actual, $descriptor)) {
                throw new RuntimeException("legal_document_create_recovery_index_descriptor_mismatch:{$name}");
            }
            if ($actual === null) {
                DB::statement($descriptor['sql']);
            }
            if (($verified = $this->indexDescriptor($name)) === null || ! $this->sameIndex($verified, $descriptor)) {
                throw new RuntimeException("legal_document_create_recovery_index_descriptor_mismatch:{$name}");
            }
        }
        foreach ($this->constraintDescriptors() as $name => $expression) {
            $actual = DB::selectOne('SELECT pg_get_constraintdef(oid, true) AS definition FROM pg_constraint WHERE conrelid = \'legal_archive_documents\'::regclass AND conname = ?', [$name]);
            if ($actual !== null && $this->normalize($actual->definition) !== $this->normalize("CHECK ({$expression}) NOT VALID")) {
                throw new RuntimeException("legal_document_create_recovery_constraint_descriptor_mismatch:{$name}");
            }
            if ($actual === null) {
                DB::statement("ALTER TABLE legal_archive_documents ADD CONSTRAINT {$name} CHECK ({$expression}) NOT VALID");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_create_recovery_lease_is_forward_only');
    }

    private function indexDescriptors(): array
    {
        return [
            'legal_docs_create_operation_unique' => ['unique' => true, 'keys' => ['organization_id', 'create_operation_id'], 'predicate' => 'create_operation_id IS NOT NULL AND deleted_at IS NULL', 'sql' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_docs_create_operation_unique ON legal_archive_documents (organization_id, create_operation_id) WHERE create_operation_id IS NOT NULL AND deleted_at IS NULL'],
            'legal_docs_manual_operation_unique' => ['unique' => true, 'keys' => ['organization_id', 'COALESCE(created_by_user_id, 0::bigint)', 'create_operation_key'], 'predicate' => 'source_type IS NULL AND create_operation_key IS NOT NULL AND deleted_at IS NULL', 'sql' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_docs_manual_operation_unique ON legal_archive_documents (organization_id, COALESCE(created_by_user_id, 0::bigint), create_operation_key) WHERE source_type IS NULL AND create_operation_key IS NOT NULL AND deleted_at IS NULL'],
            'legal_docs_create_recovery_queue' => ['unique' => false, 'keys' => ['organization_id', 'created_by_user_id', 'source_create_status', 'source_create_lease_expires_at'], 'predicate' => "source_create_status <> 'completed' AND deleted_at IS NULL", 'sql' => "CREATE INDEX CONCURRENTLY legal_docs_create_recovery_queue ON legal_archive_documents (organization_id, created_by_user_id, source_create_status, source_create_lease_expires_at) WHERE source_create_status <> 'completed' AND deleted_at IS NULL"],
        ];
    }

    private function constraintDescriptors(): array
    {
        return [
            'legal_docs_create_retry_action_check' => "source_create_retry_action IS NULL OR source_create_retry_action IN ('retry_upload', 'retry_finalize')",
            'legal_docs_create_lease_coherence_check' => "(source_create_status = 'pending' AND create_operation_id IS NOT NULL AND source_create_attempt_token IS NOT NULL AND source_create_started_at IS NOT NULL AND source_create_heartbeat_at IS NOT NULL AND source_create_lease_expires_at IS NOT NULL AND source_create_retry_action IS NOT NULL) OR (source_create_status = 'failed' AND create_operation_id IS NOT NULL AND source_create_attempt_token IS NULL AND source_create_lease_expires_at IS NULL AND source_create_retry_action IS NOT NULL) OR (source_create_status = 'completed' AND source_create_attempt_token IS NULL AND source_create_lease_expires_at IS NULL AND source_create_retry_action IS NULL)",
        ];
    }

    private function indexDescriptor(string $name): ?object
    {
        return DB::selectOne(<<<'SQL'
SELECT table_class.relname AS table_name, access_method.amname AS access_method,
       i.indisunique::integer AS indisunique, i.indisvalid::integer AS indisvalid,
       i.indisready::integer AS indisready, i.indislive::integer AS indislive,
       i.indimmediate::integer AS indimmediate, i.indisexclusion::integer AS indisexclusion,
       i.indisprimary::integer AS indisprimary, i.indnkeyatts, i.indnatts,
       COALESCE((to_jsonb(i)->>'indnullsnotdistinct')::boolean, false)::integer AS indnullsnotdistinct,
       to_json(ARRAY(SELECT pg_get_indexdef(i.indexrelid, position, true) FROM generate_series(1, i.indnkeyatts) AS position ORDER BY position)) AS key_definitions,
       pg_get_expr(i.indpred, i.indrelid) AS predicate
FROM pg_index i
JOIN pg_class index_class ON index_class.oid = i.indexrelid
JOIN pg_namespace namespace ON namespace.oid = index_class.relnamespace
JOIN pg_class table_class ON table_class.oid = i.indrelid
JOIN pg_am access_method ON access_method.oid = index_class.relam
WHERE namespace.nspname = current_schema() AND index_class.relname = ?
SQL, [$name]);
    }

    private function sameIndex(object $actual, array $expected): bool
    {
        $keys = is_string($actual->key_definitions) ? json_decode($actual->key_definitions, true) : $actual->key_definitions;

        return $actual->table_name === 'legal_archive_documents'
            && $actual->access_method === 'btree'
            && (bool) $actual->indisunique === $expected['unique']
            && (bool) $actual->indisvalid && (bool) $actual->indisready && (bool) $actual->indislive
            && (bool) $actual->indimmediate && ! (bool) $actual->indisexclusion
            && ! (bool) $actual->indisprimary && ! (bool) $actual->indnullsnotdistinct
            && (int) $actual->indnkeyatts === count($expected['keys'])
            && (int) $actual->indnatts === count($expected['keys'])
            && array_values((array) $keys) === $expected['keys']
            && $this->normalize($actual->predicate) === $this->normalize($expected['predicate']);
    }

    private function normalize(string $value): string
    {
        $value = strtolower($value);
        $value = str_replace('not valid', '', $value);
        $value = (string) preg_replace('/::[a-z_ ]+(?:\\[\\])?/', '', $value);

        return (string) preg_replace('/["()\\s]+/', '', $value);
    }
};
