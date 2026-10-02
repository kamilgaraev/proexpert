<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['estimate_items_russian_name_fts_idx', 'estimate_items_normative_rate_code_idx'] as $indexName) {
            $invalidIndex = DB::selectOne(
                'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) '.
                "AND indrelid = 'estimate_items'::regclass AND indisvalid = false",
                [$indexName],
            );
            if ($invalidIndex !== null) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$indexName);
            }
        }

        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_items_russian_name_fts_idx ON estimate_items USING GIN (to_tsvector('russian', COALESCE(name, ''))) WHERE deleted_at IS NULL");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_items_normative_rate_code_idx ON estimate_items (upper(normative_rate_code)) WHERE deleted_at IS NULL AND normative_rate_code IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS estimate_items_russian_name_fts_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS estimate_items_normative_rate_code_idx');
        }
    }
};
