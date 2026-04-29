<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('completed_works', function (Blueprint $table): void {
            if (!Schema::hasColumn('completed_works', 'journal_work_volume_id')) {
                $table->foreignId('journal_work_volume_id')
                    ->nullable()
                    ->after('journal_entry_id')
                    ->constrained('journal_work_volumes')
                    ->nullOnDelete();
            }
        });

        $this->backfillJournalWorkVolumeIds();

        Schema::table('completed_works', function (Blueprint $table): void {
            $table->index('journal_work_volume_id', 'completed_works_journal_work_volume_idx');
        });
    }

    public function down(): void
    {
        Schema::table('completed_works', function (Blueprint $table): void {
            $table->dropIndex('completed_works_journal_work_volume_idx');

            if (Schema::hasColumn('completed_works', 'journal_work_volume_id')) {
                $table->dropConstrainedForeignId('journal_work_volume_id');
            }
        });
    }

    private function backfillJournalWorkVolumeIds(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                WITH ranked_completed_works AS (
                    SELECT
                        id,
                        journal_entry_id,
                        ROW_NUMBER() OVER (PARTITION BY journal_entry_id ORDER BY id) AS row_number
                    FROM completed_works
                    WHERE journal_entry_id IS NOT NULL
                        AND journal_work_volume_id IS NULL
                        AND deleted_at IS NULL
                ),
                ranked_work_volumes AS (
                    SELECT
                        id,
                        journal_entry_id,
                        ROW_NUMBER() OVER (PARTITION BY journal_entry_id ORDER BY id) AS row_number
                    FROM journal_work_volumes
                )
                UPDATE completed_works
                SET journal_work_volume_id = ranked_work_volumes.id
                FROM ranked_completed_works
                JOIN ranked_work_volumes
                    ON ranked_work_volumes.journal_entry_id = ranked_completed_works.journal_entry_id
                    AND ranked_work_volumes.row_number = ranked_completed_works.row_number
                WHERE completed_works.id = ranked_completed_works.id
            SQL);

            return;
        }

        DB::table('completed_works')
            ->whereNotNull('journal_entry_id')
            ->whereNull('journal_work_volume_id')
            ->whereNull('deleted_at')
            ->orderBy('journal_entry_id')
            ->orderBy('id')
            ->get()
            ->groupBy('journal_entry_id')
            ->each(function ($completedWorks, $journalEntryId): void {
                $volumeIds = DB::table('journal_work_volumes')
                    ->where('journal_entry_id', $journalEntryId)
                    ->orderBy('id')
                    ->pluck('id')
                    ->values();

                foreach ($completedWorks->values() as $index => $completedWork) {
                    $volumeId = $volumeIds->get($index);

                    if ($volumeId) {
                        DB::table('completed_works')
                            ->where('id', $completedWork->id)
                            ->update(['journal_work_volume_id' => $volumeId]);
                    }
                }
            });
    }
};
