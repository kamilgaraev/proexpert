<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Enums\Schedule\TaskStatusEnum;
use App\Models\CompletedWork;
use App\Models\ContractEstimateItem;
use App\Models\ScheduleTask;
use App\Services\CompletedWork\CompletedWorkMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduleTaskSyncService
{
    private static int $derivedProgressDepth = 0;

    public static function withoutReverseSync(callable $callback): void
    {
        self::$derivedProgressDepth++;
        try {
            $callback();
        } finally {
            self::$derivedProgressDepth--;
        }
    }

    public function __construct(
        private readonly CompletedWorkMutationGuard $mutationGuard
    ) {
    }

    public function onTaskStatusChanged(ScheduleTask $task, string $oldStatus): void
    {
        if (self::$derivedProgressDepth > 0) {
            return;
        }
        $newStatus = $task->status instanceof TaskStatusEnum
            ? $task->status
            : TaskStatusEnum::from($task->status);

        match ($newStatus) {
            TaskStatusEnum::IN_PROGRESS => $this->autoCreateCompletedWork($task),
            TaskStatusEnum::COMPLETED => $this->onTaskCompleted($task),
            default => null,
        };
    }

    public function autoCreateCompletedWork(ScheduleTask $task): ?CompletedWork
    {
        if (! $this->shouldCreateFactFromTask($task)) {
            return null;
        }
        $hasActive = CompletedWork::where('schedule_task_id', $task->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->exists();

        if ($hasActive) {
            return null;
        }

        $schedule = $task->schedule;
        if (!$schedule) {
            Log::warning('[ScheduleTaskSyncService] Не удалось получить график для задачи', [
                'task_id' => $task->id,
            ]);

            return null;
        }

        $userId = $task->assigned_user_id ?? auth()->id();

        try {
            return DB::transaction(function () use ($task, $schedule, $userId): ?CompletedWork {
                $task = ScheduleTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
                if (CompletedWork::query()->where('schedule_task_id', $task->id)->where('status', '!=', 'cancelled')->exists()) {
                    return null;
                }
                $payload = $this->buildCompletedWorkPayload($task, $schedule->project_id, $userId);

                $work = CompletedWork::create([
                    'organization_id' => $task->organization_id,
                    'project_id' => $schedule->project_id,
                    'schedule_task_id' => $task->id,
                    'estimate_item_id' => $task->estimate_item_id,
                    'journal_entry_id' => null,
                    'work_origin_type' => CompletedWork::ORIGIN_SCHEDULE,
                    'planning_status' => CompletedWork::PLANNING_PLANNED,
                    'work_type_id' => $payload['work_type_id'],
                    'contract_id' => $payload['contract_id'],
                    'contractor_id' => $payload['contractor_id'],
                    'user_id' => $userId,
                    'quantity' => $payload['quantity'],
                    'completed_quantity' => $payload['completed_quantity'],
                    'price' => $payload['price'],
                    'total_amount' => $payload['total_amount'],
                    'completion_date' => now()->toDateString(),
                    'status' => 'draft',
                    'additional_info' => ['schedule_auto_draft' => true],
                ]);

                Log::info('[ScheduleTaskSyncService] Создана выполненная работа для задачи', [
                    'task_id' => $task->id,
                    'completed_work_id' => $work->id,
                ]);

                return $work;
            });
        } catch (\Exception $e) {
            Log::error('[ScheduleTaskSyncService] Ошибка создания выполненной работы', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function syncActiveCompletedWork(ScheduleTask $task): void
    {
        if (self::$derivedProgressDepth > 0) {
            return;
        }
        $task->loadMissing([
            'schedule',
            'workType',
            'estimateItem.workType',
            'estimateItem.contractLinks.contract.contractor',
        ]);

        $projectId = $task->schedule?->project_id;
        if (!$projectId) {
            return;
        }

        $userId = $task->assigned_user_id ?? auth()->id();
        $payload = $this->buildCompletedWorkPayload($task, $projectId, $userId);

        DB::transaction(function () use ($task, $payload): void {
            ScheduleTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $activeWorks = CompletedWork::query()
                ->where('schedule_task_id', $task->id)
                ->where('work_origin_type', CompletedWork::ORIGIN_SCHEDULE)
                ->where('additional_info->schedule_auto_draft', true)
                ->where('status', CompletedWork::STATUS_DRAFT)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->get();

            $activeWorks->each(function (CompletedWork $work) use ($payload, $task): void {
                $this->mutationGuard->assertMutable($work);
                $work->fill([
                    'estimate_item_id' => $task->estimate_item_id,
                    'work_origin_type' => CompletedWork::ORIGIN_SCHEDULE,
                    'planning_status' => CompletedWork::PLANNING_PLANNED,
                    'work_type_id' => $payload['work_type_id'] ?? $work->work_type_id,
                    'contract_id' => $payload['contract_id'] ?? $work->contract_id,
                    'contractor_id' => $payload['contractor_id'] ?? $work->contractor_id,
                    'quantity' => $payload['quantity'],
                    'completed_quantity' => $payload['completed_quantity'],
                    'price' => $payload['price'],
                    'total_amount' => $payload['total_amount'],
                ]);

                if ($work->isDirty()) {
                    $work->saveQuietly();
                }
            });

            if ($activeWorks->isEmpty() && $this->shouldCreateFactFromTask($task)) {
                $this->autoCreateCompletedWork($task);
            }
        }, 3);
    }

    public function onTaskCompleted(ScheduleTask $task): void
    {
        $this->syncActiveCompletedWork($task);
    }

    private function buildCompletedWorkPayload(ScheduleTask $task, int $projectId, ?int $userId): array
    {
        $task->loadMissing([
            'schedule',
            'workType',
            'estimateItem.workType',
            'estimateItem.contractLinks.contract.contractor',
        ]);

        $contractLink = $this->resolveContractLink($task);
        $quantity = $this->resolveTaskQuantity($task);
        $completedQuantity = $this->resolveCompletedQuantity($task, $quantity);
        $price = $this->resolvePrice($task, $contractLink);

        return [
            'organization_id' => $task->organization_id,
            'project_id' => $projectId,
            'schedule_task_id' => $task->id,
            'estimate_item_id' => $task->estimate_item_id,
            'journal_entry_id' => null,
            'work_origin_type' => CompletedWork::ORIGIN_SCHEDULE,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'work_type_id' => $task->work_type_id ?? $task->estimateItem?->work_type_id,
            'contract_id' => $contractLink?->contract_id,
            'contractor_id' => $contractLink?->contract?->contractor_id,
            'user_id' => $userId,
            'quantity' => $completedQuantity,
            'completed_quantity' => $completedQuantity,
            'price' => $price,
            'total_amount' => $price !== null ? round($price * $completedQuantity, 2) : null,
        ];
    }

    private function resolveCompletedQuantity(ScheduleTask $task, float $quantity): float
    {
        if ($task->completed_quantity !== null && (float) $task->completed_quantity > 0) {
            return round((float) $task->completed_quantity, 4);
        }

        if ($quantity > 0 && $task->progress_percent !== null && (float) $task->progress_percent > 0) {
            return round($quantity * ((float) $task->progress_percent / 100), 4);
        }

        return 0.0;
    }

    private function resolveTaskQuantity(ScheduleTask $task): float
    {
        if ($task->quantity !== null && (float) $task->quantity > 0) {
            return round((float) $task->quantity, 4);
        }

        $estimateItem = $task->estimateItem;
        if (!$estimateItem) {
            return 0.0;
        }

        foreach (['actual_quantity', 'quantity_total', 'quantity'] as $field) {
            if ($estimateItem->{$field} !== null && (float) $estimateItem->{$field} > 0) {
                return round((float) $estimateItem->{$field}, 4);
            }
        }

        return 0.0;
    }

    private function resolveContractLink(ScheduleTask $task): ?ContractEstimateItem
    {
        return $task->estimateItem?->contractLinks?->sortBy('id')->first();
    }

    private function resolvePrice(ScheduleTask $task, ?ContractEstimateItem $contractLink): ?float
    {
        if ($contractLink && (float) $contractLink->quantity > 0) {
            return round((float) $contractLink->amount / (float) $contractLink->quantity, 2);
        }

        $estimateItem = $task->estimateItem;
        if (!$estimateItem) {
            return null;
        }

        foreach (['actual_unit_price', 'current_unit_price', 'unit_price'] as $field) {
            if ($estimateItem->{$field} !== null && (float) $estimateItem->{$field} > 0) {
                return round((float) $estimateItem->{$field}, 2);
            }
        }

        return null;
    }

    private function shouldCreateFactFromTask(ScheduleTask $task): bool
    {
        if ($task->status === TaskStatusEnum::CANCELLED) {
            return false;
        }
        return (float) ($task->completed_quantity ?? 0) > 0
            || (float) ($task->progress_percent ?? 0) > 0
            || ($task->status?->value ?? (string) $task->status) === 'completed';
    }
}
