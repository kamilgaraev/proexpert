<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Integration;

use App\Models\ConstructionJournalEntry;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class JournalScheduleIntegrationService
{
    /**
     * Обновить прогресс задачи на основе записи журнала
     */
    public function updateTaskProgressFromEntry(ConstructionJournalEntry $entry): ?ScheduleTask
    {
        if (!$entry->schedule_task_id || $entry->status !== JournalEntryStatusEnum::APPROVED) {
            return null;
        }

        $task = $entry->scheduleTask;
        if (!$task) {
            return null;
        }

        // Рассчитать прогресс на основе фактических объемов
        $progress = $this->calculateTaskProgress($task);
        
        $task->update([
            'progress_percent' => $progress,
            'actual_start_date' => $task->actual_start_date ?? $entry->entry_date,
        ]);

        // Если прогресс 100%, установить дату фактического окончания
        if ($progress >= 100 && !$task->actual_end_date) {
            $task->update([
                'actual_end_date' => $entry->entry_date,
                'status' => \App\Enums\Schedule\TaskStatusEnum::COMPLETED,
            ]);
        }

        return $task->fresh();
    }

    /**
     * Предложить активные задачи на указанную дату
     */
    public function suggestTasksForDate(ProjectSchedule $schedule, Carbon $date): Collection
    {
        return $schedule->tasks()
            ->where(function ($query) use ($date) {
                // Задачи, которые должны выполняться в эту дату
                $query->where('planned_start_date', '<=', $date)
                    ->where(function ($q) use ($date) {
                        $q->where('planned_end_date', '>=', $date)
                            ->orWhereNull('planned_end_date');
                    });
            })
            ->whereIn('status', [
                \App\Enums\Schedule\TaskStatusEnum::NOT_STARTED,
                \App\Enums\Schedule\TaskStatusEnum::IN_PROGRESS,
            ])
            ->with(['workType', 'measurementUnit', 'estimateItem'])
            ->orderBy('planned_start_date')
            ->get();
    }

    /**
     * Связать запись журнала с задачей
     */
    public function syncEntryWithTask(ConstructionJournalEntry $entry, ScheduleTask $task): bool
    {
        $entry->update(['schedule_task_id' => $task->id]);

        // Если запись утверждена, обновить прогресс задачи
        if ($entry->status === JournalEntryStatusEnum::APPROVED) {
            $this->updateTaskProgressFromEntry($entry);
        }

        return true;
    }

    /**
     * Рассчитать прогресс задачи на основе журнала работ
     */
    protected function calculateTaskProgress(ScheduleTask $task): float
    {
        // Получить все утвержденные записи журнала для этой задачи
        $approvedEntries = $task->journalEntries()
            ->where('status', JournalEntryStatusEnum::APPROVED)
            ->with('workVolumes')
            ->get();

        if ($approvedEntries->isEmpty()) {
            return 0;
        }

        // Если задача связана с позицией сметы, рассчитать на основе объемов
        if ($task->estimate_item_id && $task->estimateItem) {
            $estimateItem = $task->estimateItem;
            $plannedQuantity = (float) $estimateItem->quantity_total;
            
            if ($plannedQuantity <= 0) {
                return 0;
            }

            // Сумма фактических объемов из записей журнала
            $actualQuantity = $approvedEntries->sum(function ($entry) {
                return $entry->workVolumes->sum('quantity');
            });

            return min(100, ($actualQuantity / $plannedQuantity) * 100);
        }

        // Если задача связана с объемом работ напрямую
        if ($task->quantity && $task->quantity > 0) {
            $actualQuantity = $approvedEntries->sum(function ($entry) {
                return $entry->workVolumes->sum('quantity');
            });

            return min(100, ($actualQuantity / $task->quantity) * 100);
        }

        // Если нет количественных показателей, считать по количеству записей
        // Предполагаем, что каждая запись = определенный прогресс
        $daysWorked = $approvedEntries->pluck('entry_date')->unique()->count();
        $plannedDuration = $task->planned_duration_days ?? 1;

        return min(100, ($daysWorked / $plannedDuration) * 100);
    }

    /**
     * Получить задачи с расхождением между плановым и фактическим прогрессом
     */
    public function getTasksWithProgressDiscrepancy(ProjectSchedule $schedule, float $threshold = 20.0): Collection
    {
        $tasks = $schedule->tasks()
            ->whereNotNull('planned_start_date')
            ->whereNotNull('planned_end_date')
            ->with(['journalEntries', 'estimateItem'])
            ->get();

        return $tasks->filter(function ($task) use ($threshold) {
            $plannedProgress = $this->calculatePlannedProgress($task);
            $actualProgress = (float) $task->progress_percent;

            $discrepancy = abs($plannedProgress - $actualProgress);
            
            return $discrepancy > $threshold;
        })->map(function ($task) {
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

    /**
     * Рассчитать плановый прогресс задачи на текущую дату
     */
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

        $totalDays = $start->diffInDays($end);
        $elapsedDays = $start->diffInDays($now);

        return ($elapsedDays / $totalDays) * 100;
    }
}

