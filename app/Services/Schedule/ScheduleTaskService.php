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
}
