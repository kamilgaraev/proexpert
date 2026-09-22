<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\PerformanceActLine;
use App\Services\Acting\ActingQuantityStatus;

final class CompletedWorkMutationGuard
{
    public function assertMutable(CompletedWork $work): void
    {
        if ($work->hasQuantityConflict()) {
            throw new BusinessLogicException(trans_message('completed_work.quantity_reconciliation_required'), 422);
        }
        if ($work->status === CompletedWork::STATUS_CONFIRMED
            || $work->work_origin_type === CompletedWork::ORIGIN_JOURNAL
            || $work->journal_entry_id !== null) {
            throw new BusinessLogicException(trans_message('completed_work.correction_required'), 422);
        }
        $this->assertNotActed($work);
    }

    public function assertNotActed(CompletedWork $work): void
    {
        $activeAct = static fn ($query) => $query->where(function ($query): void {
            $query->whereNull('status')->orWhereNotIn('status', ActingQuantityStatus::releasedStatuses());
        });
        if ($work->performanceActs()->where($activeAct)->exists()
            || PerformanceActLine::query()->where('completed_work_id', $work->id)
                ->whereHas('performanceAct', $activeAct)->exists()) {
            throw new BusinessLogicException(trans_message('completed_work.correction_required'), 422);
        }
    }
}
