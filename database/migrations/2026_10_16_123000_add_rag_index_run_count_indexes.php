<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'ai_rag_status_sources' => ['ai_rag_status_source_type_counts_idx', '(source_type, id) INCLUDE (organization_id, project_id)'],
        'ai_rag_status_chunks' => ['ai_rag_status_chunk_project_counts_idx', '(organization_id, project_id, source_id)'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => [$indexName, $definition]) {
            [$schema, $table, $index] = $this->names($tableName, $indexName);
            $state = $this->state($schema, $tableName, $indexName, $definition);
            if ($state !== null) {
                if ((int) $state->owned !== 1) { throw new RuntimeException('rag_run_count_index_target_mismatch'); }
                if ((int) $state->valid !== 1) {
                    DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
                } elseif ((int) $state->expected !== 1) {
                    throw new RuntimeException('rag_run_count_index_definition_mismatch');
                }
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS "'.$indexName.'" ON '.$table.' '.$definition);
            $state = $this->state($schema, $tableName, $indexName, $definition);
            if ($state === null || (int) $state->owned !== 1 || (int) $state->valid !== 1 || (int) $state->expected !== 1) {
                throw new RuntimeException('rag_run_count_index_not_ready');
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $tableName => [$indexName, $definition]) {
            [$schema, , $index] = $this->names($tableName, $indexName);
            $state = $this->state($schema, $tableName, $indexName, $definition);
            if ($state !== null && (int) $state->owned !== 1) { throw new RuntimeException('rag_run_count_index_target_mismatch'); }
            if ($state !== null && (int) $state->valid === 1 && (int) $state->expected !== 1) { throw new RuntimeException('rag_run_count_index_definition_mismatch'); }
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
        }
    }

    private function names(string $tableName, string $indexName): array
    {
        $schema = DB::selectOne('SELECT namespace.nspname AS name FROM pg_class relation JOIN pg_namespace namespace ON namespace.oid=relation.relnamespace WHERE relation.oid=to_regclass(?)', [$tableName])?->name;
        if (! is_string($schema)) { throw new RuntimeException('rag_run_count_table_missing'); }
        $quotedSchema = '"'.str_replace('"', '""', $schema).'"';

        return [$schema, $quotedSchema.'."'.$tableName.'"', $quotedSchema.'."'.$indexName.'"'];
    }

    private function state(string $schema, string $tableName, string $indexName, string $definition): ?object
    {
        return DB::selectOne("SELECT CASE WHEN state.indrelid=to_regclass(format('%I.%I', CAST(? AS text), CAST(? AS text))) THEN 1 ELSE 0 END AS owned,
            CASE WHEN state.indisvalid AND state.indisready AND state.indislive THEN 1 ELSE 0 END AS valid,
            CASE WHEN pg_get_indexdef(relation.oid)=format('CREATE INDEX %I ON %I.%I USING btree %s', CAST(? AS text), CAST(? AS text), CAST(? AS text), CAST(? AS text)) THEN 1 ELSE 0 END AS expected
            FROM pg_class relation JOIN pg_namespace namespace ON namespace.oid=relation.relnamespace
            JOIN pg_index state ON state.indexrelid=relation.oid WHERE namespace.nspname=? AND relation.relname=?",
            [$schema, $tableName, $indexName, $schema, $tableName, $definition, $schema, $indexName]);
    }
};
