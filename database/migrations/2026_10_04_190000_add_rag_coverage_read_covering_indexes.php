<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'ai_rag_expected_sources' => [
            'ai_rag_expected_coverage_cover_idx',
            '(organization_id, generation, source_type, entity_type) '
                .'INCLUDE (id, project_id, identity_project_id, identity_part_key, entity_id, checksum, pending_since, created_at, updated_at)',
        ],
        'ai_rag_status_sources' => [
            'ai_rag_status_coverage_cover_idx',
            '(organization_id, source_type, entity_type, entity_id) INCLUDE (id, project_id, chunk_count, indexed_chunk_count)',
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => [$indexName, $definition]) {
            $names = $this->qualifiedNames($tableName, $indexName);
            if ($names === null) { throw new RuntimeException('rag_coverage_read_table_missing: '.$tableName); }
            [$table, $index] = $names;
            $invalid = DB::selectOne('SELECT 1 FROM pg_index AS index_state '
                .'JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid '
                .'WHERE index_state.indrelid = to_regclass(?) AND index_class.relname = ? AND NOT index_state.indisvalid', [$table, $indexName]);
            if ($invalid !== null) { DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index); }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$indexName.' ON '.$table.' '.$definition);
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $tableName => [$indexName]) {
            $names = $this->qualifiedNames($tableName, $indexName);
            if ($names !== null) { DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$names[1]); }
        }
    }

    private function qualifiedNames(string $tableName, string $indexName): ?array
    {
        $schema = DB::selectOne('SELECT table_namespace.nspname AS schema_name FROM pg_class AS table_class '
            .'JOIN pg_namespace AS table_namespace ON table_namespace.oid = table_class.relnamespace '
            .'WHERE table_class.oid = to_regclass(?)', [$tableName])?->schema_name;
        if (! is_string($schema)) { return null; }
        $schema = '"'.str_replace('"', '""', $schema).'"';

        return [$schema.'."'.$tableName.'"', $schema.'."'.$indexName.'"'];
    }
};
