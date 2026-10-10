<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'ai_rag_sources_entity_lookup_idx';

    public function up(): void
    {
        $schema = $this->sourceSchema();
        $qualifiedIndex = $this->identifier($schema).'.'.$this->identifier(self::INDEX);
        $invalid = DB::selectOne('SELECT 1 FROM pg_index AS index_state '
            .'JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid '
            .'JOIN pg_namespace AS index_namespace ON index_namespace.oid = index_class.relnamespace '
            .'WHERE index_namespace.nspname = ? AND index_class.relname = ? AND NOT index_state.indisvalid', [$schema, self::INDEX]);

        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$qualifiedIndex);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX
            .' ON '.$this->identifier($schema).'.ai_rag_sources (source_type, entity_type, entity_id) INCLUDE (organization_id, project_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$this->identifier($this->sourceSchema()).'.'.$this->identifier(self::INDEX));
    }

    private function sourceSchema(): string
    {
        $table = DB::selectOne('SELECT table_namespace.nspname AS schema_name '
            .'FROM pg_class AS table_class JOIN pg_namespace AS table_namespace ON table_namespace.oid = table_class.relnamespace '
            .'WHERE table_class.oid = to_regclass(?)', ['ai_rag_sources']);

        if ($table === null) {
            throw new RuntimeException('RAG source table is unavailable.');
        }

        return $table->schema_name;
    }

    private function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
};
