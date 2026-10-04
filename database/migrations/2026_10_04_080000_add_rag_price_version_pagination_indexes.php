<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->createIndex('rag_prices_base_version_page_idx', '(dataset_version_id, id) WHERE regional_price_version_id IS NULL AND base_price > 0');
        $this->createIndex('rag_prices_regional_version_page_idx', '(regional_price_version_id, region_id, price_zone_id, period_id, id) WHERE base_price > 0');
    }

    private function createIndex(string $name, string $columns): void
    {
        $existing = DB::selectOne('SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?)', [$name]);
        if ($existing !== null && ! $existing->indisvalid) { DB::statement('DROP INDEX CONCURRENTLY '.$name); }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON estimate_resource_prices '.$columns);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS rag_prices_regional_version_page_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS rag_prices_base_version_page_idx');
    }
};
