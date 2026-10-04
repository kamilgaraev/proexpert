<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'rag_prices_access_identity_cover_idx';

    public function up(): void
    {
        $names = $this->qualifiedNames();
        if ($names === null) { throw new RuntimeException('rag_price_access_table_missing'); }
        [$table, $index] = $names;
        $invalid = DB::selectOne('SELECT 1 FROM pg_index AS index_state '
            .'JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid '
            .'WHERE index_state.indrelid = to_regclass(?) AND index_class.relname = ? AND NOT index_state.indisvalid', [$table, self::INDEX]);
        if ($invalid !== null) { DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index); }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON '.$table.' (id) '
            .'INCLUDE (regional_price_version_id, region_id, price_zone_id, period_id, dataset_version_id, construction_resource_id, base_price) '
            .'WHERE base_price > 0');
    }

    public function down(): void
    {
        $names = $this->qualifiedNames();
        if ($names !== null) { DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$names[1]); }
    }

    private function qualifiedNames(): ?array
    {
        $schema = DB::selectOne('SELECT table_namespace.nspname AS schema_name FROM pg_class AS table_class '
            .'JOIN pg_namespace AS table_namespace ON table_namespace.oid = table_class.relnamespace '
            .'WHERE table_class.oid = to_regclass(?)', ['estimate_resource_prices'])?->schema_name;
        if (! is_string($schema)) { return null; }
        $schema = '"'.str_replace('"', '""', $schema).'"';

        return [$schema.'."estimate_resource_prices"', $schema.'."'.self::INDEX.'"'];
    }
};
