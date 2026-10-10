<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\Rag\AssistantStoragePruner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['ai_rag_sources' => 'ai_rag_retired_catalog_sources_idx', 'ai_rag_expected_sources' => 'ai_rag_retired_catalog_expected_idx'] as $table => $name) {
            $existing = DB::selectOne('SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?)', ['public.'.$name]);
            if ($existing !== null && ! $existing->indisvalid) { DB::statement('DROP INDEX CONCURRENTLY public.'.$name); }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON public.'.$table.' (id) WHERE '.AssistantStoragePruner::CATALOG_PREDICATE);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.ai_rag_retired_catalog_sources_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.ai_rag_retired_catalog_expected_idx');
    }
};
