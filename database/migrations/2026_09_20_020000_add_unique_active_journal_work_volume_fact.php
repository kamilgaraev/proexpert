<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn('completed_works', 'journal_work_volume_id')) {
            return;
        }

        $duplicates = DB::table('completed_works')
            ->select('journal_work_volume_id')
            ->whereNotNull('journal_work_volume_id')
            ->whereNull('deleted_at')
            ->groupBy('journal_work_volume_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('completed_work_journal_volume_duplicates_require_reconciliation');
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS completed_works_active_journal_work_volume_unique '
            .'ON completed_works (journal_work_volume_id) '
            .'WHERE journal_work_volume_id IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS completed_works_active_journal_work_volume_unique');
        }
    }
};
