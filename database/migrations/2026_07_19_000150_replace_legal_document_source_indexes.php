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

        foreach ($this->descriptors() as $name => $descriptor) {
            $actual = $this->descriptor($name);
            if ($actual !== null && ! $this->same($actual, $descriptor)) {
                if ((bool) $actual->indisvalid && (bool) $actual->indisready && (bool) $actual->indislive) {
                    throw new RuntimeException("legal_document_source_index_descriptor_mismatch:{$name}");
                }
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
                $actual = null;
            }
            if ($actual === null) {
                DB::statement($descriptor['sql']);
            }
            if (($verified = $this->descriptor($name)) === null || ! $this->same($verified, $descriptor)) {
                throw new RuntimeException("legal_document_source_index_descriptor_mismatch:{$name}");
            }
        }
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS legal_docs_source_idempotency_unique');
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_source_index_migration_is_forward_only');
    }

    private function descriptors(): array
    {
        return [
            'legal_documents_source_identity_unique' => ['keys' => ['organization_id', 'source_type', 'source_id'], 'predicate' => 'source_type IS NOT NULL AND source_id IS NOT NULL', 'sql' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_documents_source_identity_unique ON legal_archive_documents (organization_id, source_type, source_id) WHERE source_type IS NOT NULL AND source_id IS NOT NULL'],
            'legal_documents_source_command_unique' => ['keys' => ['organization_id', 'COALESCE(created_by_user_id, 0::bigint)', 'source_idempotency_key'], 'predicate' => 'source_idempotency_key IS NOT NULL', 'sql' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_documents_source_command_unique ON legal_archive_documents (organization_id, COALESCE(created_by_user_id, 0::bigint), source_idempotency_key) WHERE source_idempotency_key IS NOT NULL'],
        ];
    }

    private function descriptor(string $name): ?object
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

    private function same(object $actual, array $expected): bool
    {
        $keys = is_string($actual->key_definitions) ? json_decode($actual->key_definitions, true) : $actual->key_definitions;

        return $actual->table_name === 'legal_archive_documents'
            && $actual->access_method === 'btree'
            && (bool) $actual->indisunique && (bool) $actual->indisvalid
            && (bool) $actual->indisready && (bool) $actual->indislive
            && (bool) $actual->indimmediate && ! (bool) $actual->indisexclusion
            && ! (bool) $actual->indisprimary && ! (bool) $actual->indnullsnotdistinct
            && (int) $actual->indnkeyatts === count($expected['keys'])
            && (int) $actual->indnatts === count($expected['keys'])
            && array_values((array) $keys) === $expected['keys']
            && $this->normalize($actual->predicate) === $this->normalize($expected['predicate']);
    }

    private function normalize(mixed $predicate): string
    {
        $normalized = strtolower((string) $predicate);
        $normalized = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $normalized);

        return (string) preg_replace('/["()\s]+/', '', $normalized);
    }
};
