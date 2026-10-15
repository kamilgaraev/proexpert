<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'ai_rag_status_coverage_cover_idx';

    public function up(): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('RAG coverage index compaction requires no active transaction');
        }
        $settings = $connection->selectOne("SELECT current_schema() AS schema_name,
            current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
        $schema = $settings->schema_name ?? null;
        if (! is_string($schema) || $schema === '') {
            throw new RuntimeException('RAG coverage index schema is unavailable');
        }
        $qualifiedIndex = '"'.str_replace('"', '""', $schema).'"."'.self::INDEX.'"';
        try {
            $connection->selectOne("SELECT set_config('statement_timeout', '180s', false), set_config('lock_timeout', '5s', false)");
            $this->assertExpectedIndex($schema);
            $connection->statement('REINDEX INDEX CONCURRENTLY '.$qualifiedIndex);
            $this->assertExpectedIndex($schema);
        } finally {
            $connection->selectOne("SELECT set_config('statement_timeout', ?, false), set_config('lock_timeout', ?, false)",
                [$settings->statement_timeout, $settings->lock_timeout]);
        }
    }

    public function down(): void {}

    private function assertExpectedIndex(string $schema): void
    {
        $index = DB::selectOne("SELECT CASE WHEN relation.relkind = 'i'
                AND source.relname = 'ai_rag_status_sources' AND source.relnamespace = namespace.oid
                AND index.indisvalid AND index.indisready AND index.indislive
                AND pg_get_indexdef(relation.oid) = format(
                    'CREATE INDEX %I ON %I.ai_rag_status_sources USING btree (organization_id, source_type, entity_type, entity_id) INCLUDE (id, project_id, chunk_count, indexed_chunk_count)', CAST(? AS text), CAST(? AS text))
                THEN 1 ELSE 0 END AS healthy
            FROM pg_class relation JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
            LEFT JOIN pg_index index ON index.indexrelid = relation.oid
            LEFT JOIN pg_class source ON source.oid = index.indrelid
            WHERE namespace.nspname = ? AND relation.relname = ?", [self::INDEX, $schema, $schema, self::INDEX]);
        if ((int) ($index->healthy ?? 0) !== 1) {
            throw new RuntimeException('RAG coverage index definition or validity differs from the expected index');
        }
        $residue = DB::selectOne("SELECT count(*) AS total FROM pg_class relation
            JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
            JOIN pg_index index ON index.indexrelid = relation.oid
            JOIN pg_class source ON source.oid = index.indrelid
            WHERE namespace.nspname = ? AND source.relname = 'ai_rag_status_sources'
                AND source.relnamespace = namespace.oid
                AND relation.relname ~ '^ai_rag_status_coverage_cover_idx_cc(new|old)[0-9]*$'", [$schema]);
        if ((int) ($residue->total ?? 0) !== 0) {
            throw new RuntimeException('RAG coverage index compaction residue requires inspection');
        }
    }
};
