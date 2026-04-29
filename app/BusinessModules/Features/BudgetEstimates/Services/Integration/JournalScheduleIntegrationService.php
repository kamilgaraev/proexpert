<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Integration;

use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\Schedule\TaskStatusEnum;
use App\Models\ConstructionJournalEntry;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Services\Schedule\ScheduleTaskCompletedWorkService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class JournalScheduleIntegrationService
{
    public function __construct(
        private readonly ScheduleTaskCompletedWorkService $scheduleTaskCompletedWorkService,
    ) {
    }

    public function updateTaskProgressFromEntry(ConstructionJournalEntry $entry): ?ScheduleTask
    {
        if (!$entry->schedule_task_id || $entry->status !== JournalEntryStatusEnum::APPROVED) {
            return null;
        }

        $task = $entry->scheduleTask;
        if (!$task) {
            return null;
        }

        if (!$task->actual_start_date) {
            $task->update(['actual_start_date' => $entry->entry_date]);
        }

        $this->scheduleTaskCompletedWorkService->syncCompletedQuantity($task);
        $task = $task->fresh();
        $progress = (float) $task->progress_percent;

        if ($progress >= 100 && !$task->actual_end_date) {
            $task->update([
                'actual_end_date' => $entry->entry_date,
                'status' => TaskStatusEnum::COMPLETED,
            ]);
        }

        return $task->fresh();
    }

    public function suggestTasksForDate(ProjectSchedule $schedule, Carbon $date): Collection
    {
        return $schedule->tasks()
            ->where(function ($query) use ($date): void {
                $query->where('planned_start_date', '<=', $date)
                    ->where(function ($nestedQuery) use ($date): void {
                        $nestedQuery->where('planned_end_date', '>=', $date)
                            ->orWhereNull('planned_end_date');
                    });
            })
            ->whereIn('status', [
                TaskStatusEnum::NOT_STARTED,
                TaskStatusEnum::IN_PROGRESS,
            ])
            ->with(['workType', 'measurementUnit', 'estimateItem'])
            ->orderBy('planned_start_date')
            ->get();
    }

    public function syncEntryWithTask(ConstructionJournalEntry $entry, ScheduleTask $task): bool
    {
        $entry->update(['schedule_task_id' => $task->id]);

        if ($entry->status === JournalEntryStatusEnum::APPROVED) {
            $this->updateTaskProgressFromEntry($entry->fresh(['scheduleTask.estimateItem', 'workVolumes']));
        }

        return true;
    }

    protected function calculateTaskProgress(ScheduleTask $task): float
    {
        $approvedEntries = $task->journalEntries()
            ->where('status', JournalEntryStatusEnum::APPROVED)
            ->with('workVolumes')
            ->get();

        if ($approvedEntries->isEmpty()) {
            return 0;
        }

        if ($task->estimate_item_id && $task->estimateItem) {
            $plannedQuantity = (float) $task->estimateItem->quantity_total;

            if ($plannedQuantity <= 0) {
                return 0;
            }

            $actualQuantity = $approvedEntries->sum(function (ConstructionJournalEntry $entry) use ($task): float {
                return (float) $entry->workVolumes
                    ->where('estimate_item_id', $task->estimate_item_id)
                    ->sum('quantity');
            });

            return min(100, ($actualQuantity / $plannedQuantity) * 100);
        }

        if ($task->quantity && $task->quantity > 0) {
            $actualQuantity = $approvedEntries->sum(function (ConstructionJournalEntry $entry): float {
                return (float) $entry->workVolumes->sum('quantity');
            });

            return min(100, ($actualQuantity / (float) $task->quantity) * 100);
        }

        $daysWorked = $approvedEntries->pluck('entry_date')->unique()->count();
        $plannedDuration = $task->planned_duration_days ?? 1;

        return min(100, ($daysWorked / $plannedDuration) * 100);
    }

    public function getTasksWithProgressDiscrepancy(ProjectSchedule $schedule, float $threshold = 20.0): Collection
    {
        $tasks = $schedule->tasks()
            ->whereNotNull('planned_start_date')
            ->whereNotNull('planned_end_date')
            ->with(['journalEntries', 'estimateItem'])
            ->get();

        return $tasks->filter(function ($task) use ($threshold): bool {
            $plannedProgress = $this->calculatePlannedProgress($task);
            $actualProgress = (float) $task->progress_percent;

            return abs($plannedProgress - $actualProgress) > $threshold;
        })->map(function ($task): array {
            $plannedProgress = $this->calculatePlannedProgress($task);
            $actualProgress = (float) $task->progress_percent;

            return [
                'task_id' => $task->id,
                'task_name' => $task->name,
                'planned_progress' => round($plannedProgress, 2),
                'actual_progress' => round($actualProgress, 2),
                'discrepancy' => round($plannedProgress - $actualProgress, 2),
                'status' => $task->status->value,
            ];
        });
    }

    protected function calculatePlannedProgress(ScheduleTask $task): float
    {
        if (!$task->planned_start_date || !$task->planned_end_date) {
            return 0;
        }

        $now = now();
        $start = $task->planned_start_date;
        $end = $task->planned_end_date;

        if ($now->lt($start)) {
            return 0;
        }

        if ($now->gte($end)) {
            return 100;
        }

        $totalDays = max(1, $start->diffInDays($end));
        $elapsedDays = $start->diffInDays($now);

        return ($elapsedDays / $totalDays) * 100;
    }
}
