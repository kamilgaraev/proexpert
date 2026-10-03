<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'ai_rag_sources_status_identity_idx' => 'ai_rag_sources (organization_id, source_type, entity_type, entity_id) INCLUDE (id, project_id)',
        'ai_rag_chunks_status_scope_idx' => 'ai_rag_chunks (organization_id, source_id, project_id)',
        'ai_rag_chunks_indexed_status_scope_idx' => 'ai_rag_chunks (organization_id, source_id, project_id) WHERE embedding IS NOT NULL',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            $invalid = DB::selectOne('SELECT 1 FROM pg_index AS index_state '
                .'JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid '
                .'JOIN pg_namespace AS index_namespace ON index_namespace.oid = index_class.relnamespace '
                .'WHERE index_namespace.nspname = current_schema() AND index_class.relname = ? AND NOT index_state.indisvalid', [$name]);
            if ($invalid !== null) { DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name); }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$definition);
        }
        DB::statement('VACUUM (ANALYZE) ai_rag_sources');
        DB::statement('VACUUM (ANALYZE) ai_rag_chunks');
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
