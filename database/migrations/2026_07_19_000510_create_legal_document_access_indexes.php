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
        foreach ($this->indexes() as $name => $expected) {
            $actual = $this->indexDescriptor($name);
            if ($actual !== null && ! $this->sameDescriptor($actual, $expected)) {
                if ((bool) $actual->indisvalid && (bool) $actual->indisready && (bool) $actual->indislive) {
                    throw new RuntimeException("legal_document_access_index_descriptor_mismatch:{$name}");
                }
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
                $actual = null;
            }
            if ($actual === null) {
                DB::statement($expected['sql']);
            }
            $verified = $this->indexDescriptor($name);
            if ($verified === null || ! $this->sameDescriptor($verified, $expected)) {
                throw new RuntimeException("legal_document_access_index_descriptor_mismatch:{$name}");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_access_migrations_are_forward_only');
    }

    private function indexes(): array
    {
        return [
            'counterparties_ownership_unique' => $this->descriptor('counterparties', true, ['id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY counterparties_ownership_unique ON counterparties (id, organization_id)'),
            'legal_document_party_snapshot_sets_version_unique' => $this->descriptor('legal_document_party_snapshot_sets', true, ['document_version_id', 'document_id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_document_party_snapshot_sets_version_unique ON legal_document_party_snapshot_sets (document_version_id, document_id, organization_id)'),
            'legal_document_party_snapshot_sets_ownership_unique' => $this->descriptor('legal_document_party_snapshot_sets', true, ['id', 'document_version_id', 'document_id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_document_party_snapshot_sets_ownership_unique ON legal_document_party_snapshot_sets (id, document_version_id, document_id, organization_id)'),
            'legal_document_parties_ownership_unique' => $this->descriptor('legal_document_parties', true, ['id', 'snapshot_set_id', 'document_version_id', 'document_id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_document_parties_ownership_unique ON legal_document_parties (id, snapshot_set_id, document_version_id, document_id, organization_id)'),
            'legal_document_access_ownership_unique' => $this->descriptor('legal_document_access_grants', true, ['id', 'document_id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_document_access_ownership_unique ON legal_document_access_grants (id, document_id, organization_id)'),
            'legal_document_comments_ownership_unique' => $this->descriptor('legal_document_comments', true, ['id', 'document_id', 'document_version_id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_document_comments_ownership_unique ON legal_document_comments (id, document_id, document_version_id, organization_id)'),
            'legal_documents_source_identity_unique' => $this->descriptor('legal_archive_documents', true, ['organization_id', 'source_type', 'source_id'], 'source_type IS NOT NULL AND source_id IS NOT NULL', 'CREATE UNIQUE INDEX CONCURRENTLY legal_documents_source_identity_unique ON legal_archive_documents (organization_id, source_type, source_id) WHERE source_type IS NOT NULL AND source_id IS NOT NULL'),
            'legal_document_access_active_subject_unique' => $this->descriptor('legal_document_access_grants', true, ['organization_id', 'document_id', 'subject_kind', 'subject_organization_id', 'COALESCE(subject_user_id, 0::bigint)', "COALESCE(subject_role_slug, ''::character varying)"], 'revoked_at IS NULL', "CREATE UNIQUE INDEX CONCURRENTLY legal_document_access_active_subject_unique ON legal_document_access_grants (organization_id, document_id, subject_kind, subject_organization_id, COALESCE(subject_user_id, 0::bigint), COALESCE(subject_role_slug, ''::character varying)) WHERE revoked_at IS NULL"),
            'legal_document_access_lookup_idx' => $this->descriptor('legal_document_access_grants', false, ['subject_organization_id', 'subject_user_id', 'document_id', 'expires_at'], 'revoked_at IS NULL', 'CREATE INDEX CONCURRENTLY legal_document_access_lookup_idx ON legal_document_access_grants (subject_organization_id, subject_user_id, document_id, expires_at) WHERE revoked_at IS NULL'),
            'legal_document_comments_create_idempotency_unique' => $this->descriptor('legal_document_comments', true, ['organization_id', 'document_id', 'author_user_id', 'idempotency_key'], 'idempotency_key IS NOT NULL', 'CREATE UNIQUE INDEX CONCURRENTLY legal_document_comments_create_idempotency_unique ON legal_document_comments (organization_id, document_id, author_user_id, idempotency_key) WHERE idempotency_key IS NOT NULL'),
            'legal_document_comments_open_idx' => $this->descriptor('legal_document_comments', false, ['organization_id', 'document_id', 'document_version_id', 'is_blocking', 'created_at'], "status = 'open'", "CREATE INDEX CONCURRENTLY legal_document_comments_open_idx ON legal_document_comments (organization_id, document_id, document_version_id, is_blocking, created_at) WHERE status = 'open'"),
        ];
    }

    private function descriptor(string $table, bool $unique, array $keys, ?string $predicate, string $sql): array
    {
        return [
            'table' => $table, 'unique' => $unique, 'immediate' => true, 'exclusion' => false,
            'primary' => false, 'method' => 'btree', 'keys' => $keys, 'include' => [],
            'nulls_not_distinct' => false, 'predicate' => $predicate, 'sql' => $sql,
        ];
    }

    private function sameDescriptor(object $actual, array $expected): bool
    {
        return $actual->table_name === $expected['table']
            && (bool) $actual->indisunique === $expected['unique']
            && (bool) $actual->indisvalid
            && (bool) $actual->indisready
            && (bool) $actual->indislive
            && (bool) $actual->indimmediate === $expected['immediate']
            && (bool) $actual->indisexclusion === $expected['exclusion']
            && (bool) $actual->indisprimary === $expected['primary']
            && $actual->access_method === $expected['method']
            && (bool) $actual->indnullsnotdistinct === $expected['nulls_not_distinct']
            && (int) $actual->indnkeyatts === count($expected['keys'])
            && (int) $actual->indnatts === count($expected['keys']) + count($expected['include'])
            && $this->definitions($actual->key_definitions) === $expected['keys']
            && $this->definitions($actual->include_definitions) === $expected['include']
            && $this->normalizePredicate($actual->predicate) === $this->normalizePredicate($expected['predicate']);
    }

    private function indexDescriptor(string $name): ?object
    {
        return DB::selectOne(
            <<<'SQL'
SELECT table_class.relname AS table_name,
       access_method.amname AS access_method,
       i.indisunique::integer AS indisunique,
       i.indisvalid::integer AS indisvalid,
       i.indisready::integer AS indisready,
       i.indislive::integer AS indislive,
       i.indimmediate::integer AS indimmediate,
       i.indisexclusion::integer AS indisexclusion,
       i.indisprimary::integer AS indisprimary,
       i.indnkeyatts, i.indnatts,
       COALESCE((to_jsonb(i)->>'indnullsnotdistinct')::boolean, false)::integer AS indnullsnotdistinct,
       to_json(ARRAY(
           SELECT pg_get_indexdef(i.indexrelid, position, true)
           FROM generate_series(1, i.indnkeyatts) AS position ORDER BY position
       )) AS key_definitions,
       to_json(ARRAY(
           SELECT pg_get_indexdef(i.indexrelid, position, true)
           FROM generate_series(i.indnkeyatts + 1, i.indnatts) AS position ORDER BY position
       )) AS include_definitions,
       pg_get_expr(i.indpred, i.indrelid) AS predicate
FROM pg_index i
JOIN pg_class index_class ON index_class.oid = i.indexrelid
JOIN pg_namespace namespace ON namespace.oid = index_class.relnamespace
JOIN pg_class table_class ON table_class.oid = i.indrelid
JOIN pg_am access_method ON access_method.oid = index_class.relam
WHERE namespace.nspname = current_schema() AND index_class.relname = ?
SQL,
            [$name],
        );
    }

    private function definitions(mixed $definitions): array
    {
        if (is_array($definitions)) {
            return array_values(array_map('strval', $definitions));
        }
        if (! is_string($definitions)) {
            return [];
        }
        $decoded = json_decode($definitions, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function normalizePredicate(mixed $predicate): ?string
    {
        if ($predicate === null || trim((string) $predicate) === '') {
            return null;
        }
        $normalized = strtolower((string) $predicate);
        $normalized = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $normalized);
        $normalized = (string) preg_replace('/["()\s]+/', '', $normalized);
        $normalized = str_replace(['=anyarray[', ']'], ['in', ''], $normalized);

        return $normalized;
    }
};
