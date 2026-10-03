<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $diagnostics = static fn (): array => DB::selectFromWriteConnection('SELECT c.relname, c.relpages, c.relallvisible, '
            .'pg_has_role(current_user, c.relowner, \'USAGE\') AS owns_table '
            .'FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            .'WHERE n.nspname = current_schema() AND c.relname IN (?, ?)', ['ai_rag_sources', 'ai_rag_chunks']);
        $before = $diagnostics();
        DB::statement('VACUUM (ANALYZE, INDEX_CLEANUP ON) ai_rag_sources');
        DB::statement('VACUUM (ANALYZE, INDEX_CLEANUP ON) ai_rag_chunks');
        echo 'RAG planner maintenance: '.json_encode(['before' => $before, 'after' => $diagnostics()], JSON_THROW_ON_ERROR).PHP_EOL;
    }

    public function down(): void {}
};
