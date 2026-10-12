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

        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND indrelid = to_regclass(?) AND NOT indisvalid',
            ['ai_rag_runs_recovery_candidates_idx', 'ai_rag_index_runs'],
        );
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ai_rag_runs_recovery_candidates_idx');
        }

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS ai_rag_runs_recovery_candidates_idx
            ON ai_rag_index_runs (id)
            WHERE status = 'running'
                OR (status = 'queued' AND (
                    entity_type IS NULL
                    OR (source_type = 'file_document' AND entity_type = 'file' AND mode = 'scheduled')
                    OR last_error LIKE 'dispatch\_pending:%'
                ))
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ai_rag_runs_recovery_candidates_idx');
    }
};
