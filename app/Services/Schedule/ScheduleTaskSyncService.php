<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Enums\Schedule\TaskStatusEnum;
use App\Models\CompletedWork;
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
                $work = CompletedWork::create([
                    'organization_id'    => $task->organization_id,
                    'project_id'         => $schedule->project_id,
                    'schedule_task_id'   => $task->id,
                    'work_type_id'       => $task->work_type_id,
                    'user_id'            => $userId,
                    'quantity'           => (float) ($task->quantity ?? 0),
                    'completed_quantity' => (float) ($task->completed_quantity ?? 0),
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
}
