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
        foreach ($this->indexes() as $name => [$table, $definition]) {
            $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND indrelid = to_regclass(?) AND NOT indisvalid', [$name, $table]);
            if ($invalid !== null) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$table.' '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_keys($this->indexes()) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }

    private function indexes(): array
    {
        return [
            'ai_usage_records_period_idx' => ['ai_usage_records', '(occurred_at, id)'],
            'ai_rag_runs_latest_attempt_idx' => ['ai_rag_index_runs', '(organization_id, created_at DESC NULLS LAST)'],
            'ai_rag_runs_bulk_scope_idx' => ['ai_rag_index_runs', '(organization_id, source_type, status, queued_at, finished_at) WHERE entity_type IS NULL AND project_id IS NULL'],
        ];
    }
};
