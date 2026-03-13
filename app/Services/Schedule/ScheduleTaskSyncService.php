<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Enums\Schedule\TaskStatusEnum;
use App\Models\CompletedWork;
use App\Models\ContractEstimateItem;
use App\Models\ScheduleTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduleTaskSyncService
{
    public function __construct(
        private readonly ScheduleTaskCompletedWorkService $syncService
    ) {}

    public function onTaskStatusChanged(ScheduleTask $task, string $oldStatus): void
    {
        $newStatus = $task->status instanceof TaskStatusEnum
            ? $task->status
            : TaskStatusEnum::from($task->status);

        match ($newStatus) {
            TaskStatusEnum::IN_PROGRESS => $this->autoCreateCompletedWork($task),
            TaskStatusEnum::COMPLETED   => $this->onTaskCompleted($task),
            TaskStatusEnum::CANCELLED   => $this->onTaskCancelled($task),
            default                     => null,
        };
    }

    public function autoCreateCompletedWork(ScheduleTask $task): ?CompletedWork
    {
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
            return DB::transaction(function () use ($task, $schedule, $userId): CompletedWork {
                $payload = $this->buildCompletedWorkPayload($task, $schedule->project_id, $userId);

                $work = CompletedWork::create([
                    'organization_id'    => $task->organization_id,
                    'project_id'         => $schedule->project_id,
                    'schedule_task_id'   => $task->id,
                    'work_type_id'       => $payload['work_type_id'],
                    'contract_id'        => $payload['contract_id'],
                    'contractor_id'      => $payload['contractor_id'],
                    'user_id'            => $userId,
                    'quantity'           => $payload['quantity'],
                    'completed_quantity' => $payload['completed_quantity'],
                    'price'              => $payload['price'],
                    'total_amount'       => $payload['total_amount'],
                    'completion_date'    => now()->toDateString(),
                    'status'             => 'draft',
                ]);

                Log::info('[ScheduleTaskSyncService] Создана выполненная работа для задачи', [
                    'task_id'           => $task->id,
                    'completed_work_id' => $work->id,
                ]);

                return $work;
            });
        } catch (\Exception $e) {
            Log::error('[ScheduleTaskSyncService] Ошибка создания выполненной работы', [
                'task_id' => $task->id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function syncActiveCompletedWork(ScheduleTask $task): void
    {
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

        CompletedWork::query()
            ->where('schedule_task_id', $task->id)
            ->whereIn('status', ['draft', 'pending', 'in_review'])
            ->whereNull('deleted_at')
            ->get()
            ->each(function (CompletedWork $work) use ($payload): void {
                $work->fill([
                    'work_type_id'       => $payload['work_type_id'] ?? $work->work_type_id,
                    'contract_id'        => $payload['contract_id'] ?? $work->contract_id,
                    'contractor_id'      => $payload['contractor_id'] ?? $work->contractor_id,
                    'quantity'           => $payload['quantity'],
                    'completed_quantity' => $payload['completed_quantity'],
                    'price'              => $payload['price'],
                    'total_amount'       => $payload['total_amount'],
                ]);

                if ($work->isDirty()) {
                    $work->saveQuietly();
                }
            });
    }

    public function onTaskCompleted(ScheduleTask $task): void
    {
        try {
            DB::transaction(function () use ($task): void {
                $openStatuses = ['draft', 'pending', 'in_review'];

                $openWorks = CompletedWork::where('schedule_task_id', $task->id)
                    ->whereIn('status', $openStatuses)
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->get();

                if ($openWorks->isEmpty()) {
                    return;
                }

                $openWorks->each(fn(CompletedWork $w) => $w->updateQuietly(['status' => 'confirmed']));

                $taskQuantity = (float) ($task->quantity ?? 0);
                if ($taskQuantity <= 0) {
                    return;
                }

                $sumCompleted = (float) CompletedWork::where('schedule_task_id', $task->id)
                    ->whereNull('deleted_at')
                    ->sum('completed_quantity');

                if ($sumCompleted < $taskQuantity) {
                    $diff = round($taskQuantity - $sumCompleted, 4);
                    $lastWork = $openWorks->last();
                    $lastWork->updateQuietly([
                        'completed_quantity' => round((float) $lastWork->completed_quantity + $diff, 4),
                    ]);
                }

                $task->refresh();
                $this->syncService->syncCompletedQuantity($task);

                Log::info('[ScheduleTaskSyncService] Выполненные работы задачи подтверждены', [
                    'task_id'        => $task->id,
                    'confirmed_count' => $openWorks->count(),
                ]);
            });
        } catch (\Exception $e) {
            Log::error('[ScheduleTaskSyncService] Ошибка при завершении задачи', [
                'task_id' => $task->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    public function onTaskCancelled(ScheduleTask $task): void
    {
        try {
            $count = CompletedWork::where('schedule_task_id', $task->id)
                ->whereIn('status', ['draft', 'pending'])
                ->whereNull('deleted_at')
                ->update(['status' => 'cancelled']);

            Log::info('[ScheduleTaskSyncService] Выполненные работы задачи отменены', [
                'task_id'          => $task->id,
                'cancelled_count'  => $count,
            ]);
        } catch (\Exception $e) {
            Log::error('[ScheduleTaskSyncService] Ошибка при отмене задачи', [
                'task_id' => $task->id,
                'error'   => $e->getMessage(),
            ]);
        }
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
            'organization_id'    => $task->organization_id,
            'project_id'         => $projectId,
            'schedule_task_id'   => $task->id,
            'work_type_id'       => $task->work_type_id ?? $task->estimateItem?->work_type_id,
            'contract_id'        => $contractLink?->contract_id,
            'contractor_id'      => $contractLink?->contract?->contractor_id,
            'user_id'            => $userId,
            'quantity'           => $quantity,
            'completed_quantity' => $completedQuantity,
            'price'              => $price,
            'total_amount'       => $price !== null ? round($price * $completedQuantity, 2) : null,
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

    private function resolvePrice(ScheduleTask $task, ?ContractEstimateItem $contractLink): ?float
    {
        $linkedQuantity = (float) ($contractLink?->quantity ?? 0);
        $linkedAmount = $contractLink?->amount !== null ? (float) $contractLink->amount : null;

        if ($linkedAmount !== null && $linkedQuantity > 0) {
            return round($linkedAmount / $linkedQuantity, 2);
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

        $estimateQuantity = $this->resolveTaskQuantity($task);
        foreach (['current_total_amount', 'total_amount'] as $field) {
            if ($estimateItem->{$field} !== null && (float) $estimateItem->{$field} > 0 && $estimateQuantity > 0) {
                return round((float) $estimateItem->{$field} / $estimateQuantity, 2);
            }
        }

        return null;
    }

    private function resolveContractLink(ScheduleTask $task): ?ContractEstimateItem
    {
        return $task->estimateItem?->contractLinks
            ?->sortBy('id')
            ->first();
    }
}
