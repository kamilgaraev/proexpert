<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        $name = 'ai_rag_expected_retention_idx';
        $existing = DB::selectOne('SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?)', [$name]);
        if ($existing !== null && ! $existing->indisvalid) {
            DB::statement('DROP INDEX CONCURRENTLY '.$name);
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON ai_rag_expected_sources (organization_id, created_at, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ai_rag_expected_retention_idx');
    }
};
