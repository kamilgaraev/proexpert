<?php

namespace App\Observers;

use App\Models\CompletedWork;
use App\Services\Schedule\ScheduleTaskCompletedWorkService;
use Illuminate\Support\Facades\Log;

class CompletedWorkObserver
{
    public function __construct(
        private readonly ScheduleTaskCompletedWorkService $scheduleTaskService
    ) {}

    public function creating(CompletedWork $work): void
    {
        $this->calculateAmounts($work);
    }

    public function updating(CompletedWork $work): void
    {
        if ($work->wasChanged(['quantity', 'price', 'total_amount']) || $work->isDirty(['quantity', 'price', 'total_amount'])) {
            $this->calculateAmounts($work);
        }
    }

    public function saved(CompletedWork $work): void
    {
        $this->recalculateFromMaterials($work);
        $this->syncScheduleTask($work);
    }

    public function deleted(CompletedWork $work): void
    {
        $this->syncScheduleTask($work);
    }

    public function restored(CompletedWork $work): void
    {
        $this->syncScheduleTask($work);
    }

    private function syncScheduleTask(CompletedWork $work): void
    {
        if (!$work->schedule_task_id) {
            return;
        }

        try {
            $task = $work->scheduleTask;
            if ($task) {
                $this->scheduleTaskService->syncCompletedQuantity($task);
            }
        } catch (\Exception $e) {
            Log::error('Failed to sync schedule task completed quantity', [
                'completed_work_id' => $work->id,
                'schedule_task_id'  => $work->schedule_task_id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    protected function calculateAmounts(CompletedWork $work): void
    {
        try {
            $baseQuantity = $this->resolveAmountQuantity($work);

            if ($work->price !== null && $baseQuantity > 0 && $work->total_amount === null) {
                $work->total_amount = round($work->price * $baseQuantity, 2);
            }

            if ($work->total_amount !== null && $baseQuantity > 0 && $work->price === null) {
                $work->price = round($work->total_amount / $baseQuantity, 2);
            }
        } catch (\Exception $e) {
            Log::error('Failed to calculate CompletedWork amounts', [
                'work_id' => $work->id ?? 'new',
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function recalculateFromMaterials(CompletedWork $work): void
    {
        try {
            if ($work->price === null && $work->total_amount === null) {
                $materialsSum = 0;

                if (!$work->relationLoaded('materials')) {
                    $work->load('materials');
                }

                foreach ($work->materials as $material) {
                    $pivotAmount = $material->pivot->total_amount ?? 0;
                    if ($pivotAmount <= 0) {
                        $quantity = $material->pivot->quantity ?? 0;
                        $unitPrice = $material->pivot->unit_price ?? 0;
                        $pivotAmount = $quantity * $unitPrice;
                    }
                    $materialsSum += $pivotAmount;
                }

                if ($materialsSum > 0) {
                    $baseQuantity = $this->resolveAmountQuantity($work);
                    $work->update([
                        'total_amount' => round($materialsSum, 2),
                        'price'        => $baseQuantity > 0 ? round($materialsSum / $baseQuantity, 2) : 0,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to recalculate CompletedWork from materials', [
                'work_id' => $work->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function resolveAmountQuantity(CompletedWork $work): float
    {
        if ($work->completed_quantity !== null && (float) $work->completed_quantity > 0) {
            return (float) $work->completed_quantity;
        }

        return (float) ($work->quantity ?? 0);
    }
}

