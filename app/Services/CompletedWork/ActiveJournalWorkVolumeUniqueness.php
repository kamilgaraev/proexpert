<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ActiveJournalWorkVolumeUniqueness
{
    public const INDEX_NAME = 'completed_works_active_journal_work_volume_unique';

    /**
     * @return list<object{journal_work_volume_id: mixed, cnt: int|string}>
     */
    public function duplicateGroups(): array
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn('completed_works', 'journal_work_volume_id')) {
            return [];
        }

        return DB::table('completed_works')
            ->select('journal_work_volume_id', DB::raw('COUNT(*)::int AS cnt'))
            ->whereNotNull('journal_work_volume_id')
            ->whereNull('deleted_at')
            ->groupBy('journal_work_volume_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->all();
    }

    public function hasDuplicates(): bool
    {
        return $this->duplicateGroups() !== [];
    }

    public function indexExists(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $row = DB::selectOne(
            'SELECT to_regclass(?) IS NOT NULL AS exists',
            [self::INDEX_NAME]
        );

        return (bool) ($row->exists ?? false);
    }

    /**
     * @return 'created'|'already_exists'|'skipped_duplicates'|'unsupported'
     */
    public function ensureUniqueIndex(): string
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn('completed_works', 'journal_work_volume_id')) {
            return 'unsupported';
        }

        if ($this->indexExists()) {
            return 'already_exists';
        }

        if ($this->hasDuplicates()) {
            return 'skipped_duplicates';
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX_NAME.' '
            .'ON completed_works (journal_work_volume_id) '
            .'WHERE journal_work_volume_id IS NOT NULL AND deleted_at IS NULL'
        );

        return 'created';
    }
}
