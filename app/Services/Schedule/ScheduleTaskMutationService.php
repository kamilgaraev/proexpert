<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScheduleTaskMutationService
{
    public function __construct(
        protected AutoSchedulingService $autoSchedulingService,
        protected ScheduleTaskService $scheduleTaskService,
    ) {
    }

    /**
     * @return array{task: ScheduleTask, affected_tasks: Collection<int, ScheduleTask>}
     */
    public function updateTask(ProjectSchedule $schedule, ScheduleTask $task, array $validatedData): array
    {
        $propagateChildrenDates = (bool) ($validatedData['propagate_children_dates'] ?? false);
        unset($validatedData['propagate_children_dates']);

        $originalStartDate = $task->planned_start_date?->copy();
        $shouldInvalidateCriticalPath =
            array_key_exists('planned_start_date', $validatedData)
            || array_key_exists('planned_end_date', $validatedData)
            || array_key_exists('progress_percent', $validatedData)
            || array_key_exists('completed_quantity', $validatedData);
        $shouldRecalculateProgress =
            array_key_exists('progress_percent', $validatedData)
            || array_key_exists('completed_quantity', $validatedData);

        DB::transaction(function () use (
            $schedule,
            $task,
            $validatedData,
            $propagateChildrenDates,
            $originalStartDate,
            $shouldInvalidateCriticalPath,
            $shouldRecalculateProgress,
        ): void {
            $this->autoSchedulingService->clearUpdatedTasks();

            $task->update($validatedData);
            $task->refresh();
            $this->autoSchedulingService->rememberTask($task);

            if (array_key_exists('intervals', $validatedData)) {
                $this->scheduleTaskService->syncTaskIntervals($task, $validatedData['intervals']);
                $task->refresh();
                $this->autoSchedulingService->rememberTask($task);
            }

            if (
                array_key_exists('completed_quantity', $validatedData)
                && (float) $task->quantity > 0
            ) {
                $task->recalculateProgressFromQuantity();
                $task->refresh();
                $this->autoSchedulingService->rememberTask($task);
            }

            if (
                $propagateChildrenDates
                && $task->task_type->hasChildren()
                && $originalStartDate !== null
                && $task->planned_start_date !== null
            ) {
                $deltaDays = (int) round(
                    ($task->planned_start_date->startOfDay()->getTimestamp() - $originalStartDate->startOfDay()->getTimestamp()) / 86400
                );

                if ($deltaDays !== 0) {
                    $this->autoSchedulingService->shiftDescendants($task, $deltaDays);
                    $task->refresh();
                    $this->autoSchedulingService->rememberTask($task);
                }
            }

            if ($shouldInvalidateCriticalPath) {
                $schedule->update(['critical_path_calculated' => false]);
            }

            if ($shouldRecalculateProgress) {
                $schedule->recalculateProgress();
            }
        });

        $taskRelations = [
            'parentTask',
            'childTasks',
            'assignedUser',
            'workType',
            'measurementUnit',
            'predecessorDependencies',
            'successorDependencies',
            'intervals',
        ];

        $freshTask = ScheduleTask::query()
            ->with($taskRelations)
            ->withCount('completedWorks')
            ->findOrFail($task->id);

        $affectedTaskIds = collect($this->autoSchedulingService->getUpdatedTasks())
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        $affectedTasks = $affectedTaskIds->isEmpty()
            ? collect()
            : ScheduleTask::query()
                ->with($taskRelations)
                ->withCount('completedWorks')
                ->whereIn('id', $affectedTaskIds)
                ->get()
                ->sortBy(fn (ScheduleTask $affectedTask) => (int) $affectedTaskIds->search($affectedTask->id))
                ->values();

        return [
            'task' => $freshTask,
            'affected_tasks' => $affectedTasks,
        ];
    }
}
