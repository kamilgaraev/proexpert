<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduleTaskService
{
    /**
     * Переиндексировать задачи в графике для обеспечения корректного sort_order
     */
    public function reorderTasks(ProjectSchedule $schedule): void
    {
        Log::info('[ScheduleTaskService] Starting task reordering', ['schedule_id' => $schedule->id]);

        DB::transaction(function () use ($schedule) {
            $this->reorderLevel($schedule->id, null);
        });

        Log::info('[ScheduleTaskService] Task reordering completed', ['schedule_id' => $schedule->id]);
    }

    /**
     * Рекурсивная переиндексация уровня задач
     */
    protected function reorderLevel(int $scheduleId, ?int $parentId): void
    {
        $tasks = ScheduleTask::where('schedule_id', $scheduleId)
            ->where('parent_task_id', $parentId)
            ->orderBy('planned_start_date')
            ->orderBy('id')
            ->get();

        foreach ($tasks as $index => $task) {
            $sortOrder = $index + 1;
            
            if ($task->sort_order !== $sortOrder) {
                $task->update(['sort_order' => $sortOrder]);
            }

            // Рекурсивно обрабатываем дочерние задачи
            $this->reorderLevel($scheduleId, $task->id);
        }
    }

    /**
     * Получить следующий sort_order для нового элемента на указанном уровне
     */
    public function getNextSortOrder(int $scheduleId, ?int $parentId): int
    {
        $maxSortOrder = ScheduleTask::where('schedule_id', $scheduleId)
            ->where('parent_task_id', $parentId)
            ->max('sort_order');

        return ($maxSortOrder ?? 0) + 1;
    }

    /**
     * Вставить задачу после указанной, сдвинув sort_order остальных задач
     */
    public function insertTaskAfter(int $scheduleId, ?int $afterTaskId, ?int $parentId): int
    {
        if ($afterTaskId === null) {
            return $this->getNextSortOrder($scheduleId, $parentId);
        }

        $afterTask = ScheduleTask::where('id', $afterTaskId)
            ->where('schedule_id', $scheduleId)
            ->first();

        if (!$afterTask) {
            return $this->getNextSortOrder($scheduleId, $parentId);
        }

        $insertAt = $afterTask->sort_order + 1;

        DB::transaction(function () use ($scheduleId, $parentId, $insertAt) {
            ScheduleTask::where('schedule_id', $scheduleId)
                ->where('parent_task_id', $parentId)
                ->where('sort_order', '>=', $insertAt)
                ->increment('sort_order');
        });

        return $insertAt;
    }

    /**
     * Синхронизация интервалов задачи
     * Удаляет старые интервалы и создает новые на основе переданного массива
     */
    public function syncTaskIntervals(ScheduleTask $task, ?array $intervalsData): void
    {
        if ($intervalsData === null) {
            return;
        }

        DB::transaction(function () use ($task, $intervalsData) {
            // Удаляем старые интервалы (Observer пересчитает даты родителя, если нужно, 
            // но мы сделаем это один раз в конце через коллективный метод)
            $task->intervals()->delete();

            if (empty($intervalsData)) {
                return;
            }

            // Создаем новые
            $intervalsToInsert = [];
            foreach ($intervalsData as $index => $intervalData) {
                $intervalsToInsert[] = [
                    'schedule_task_id' => $task->id,
                    'start_date' => $intervalData['start_date'],
                    'end_date' => $intervalData['end_date'],
                    'duration_days' => $intervalData['duration_days'],
                    'sort_order' => $index,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            \App\Models\ScheduleTaskInterval::insert($intervalsToInsert);
            
            // Принудительно вызываем пересчет дат у задачи
            $task->syncDatesFromIntervals();
        });
    }
}
