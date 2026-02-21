<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use App\Enums\Schedule\DependencyTypeEnum;
use App\Enums\Schedule\TaskTypeEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoSchedulingService
{
    /**
     * Синхронизировать даты родительских задач (SUMMARY) вверх по иерархии
     */
    public function syncParentDates(ScheduleTask $task): void
    {
        if (!$task->parent_task_id) {
            return;
        }

        $parent = $task->parentTask;
        if (!$parent || !$parent->task_type->hasChildren()) {
            return;
        }

        $siblings = $parent->childTasks()
            ->whereNotNull('planned_start_date')
            ->whereNotNull('planned_end_date')
            ->get();

        if ($siblings->isEmpty()) {
            return;
        }

        $minStart = $siblings->min('planned_start_date');
        $maxEnd = $siblings->max('planned_end_date');

        if ($minStart && $maxEnd) {
            $startDate = Carbon::parse($minStart);
            $endDate = Carbon::parse($maxEnd);
            $duration = $startDate->diffInDays($endDate) + 1;

            $parent->update([
                'planned_start_date' => $startDate->toDateString(),
                'planned_end_date' => $endDate->toDateString(),
                'planned_duration_days' => $duration,
            ]);

            // Рекурсивно идем выше
            $this->syncParentDates($parent);
        }
    }

    /**
     * Применить каскадные обновления для последователей на основе зависимостей
     */
    public function applyCascadeUpdates(ScheduleTask $task): void
    {
        $schedule = $task->schedule;
        
        // Проверяем настройку автопланирования (по умолчанию true)
        $settings = $schedule->calculation_settings ?? [];
        $autoSchedulingEnabled = $settings['auto_scheduling_enabled'] ?? true;

        if (!$autoSchedulingEnabled) {
            return;
        }

        $dependencies = $task->successorDependencies()
            ->with('successorTask')
            ->get();

        foreach ($dependencies as $dependency) {
            $successor = $dependency->successorTask;
            if (!$successor) continue;

            $newStartDate = $this->calculateNewStartDate($task, $dependency);
            
            if ($newStartDate && (!$successor->planned_start_date || !$newStartDate->equalTo($successor->planned_start_date))) {
                $duration = $successor->planned_duration_days ?? 1;
                $newEndDate = $newStartDate->copy()->addDays($duration - 1);

                $successor->update([
                    'planned_start_date' => $newStartDate->toDateString(),
                    'planned_end_date' => $newEndDate->toDateString(),
                ]);

                // Рекурсивно обновляем последователей последователя
                $this->applyCascadeUpdates($successor);
                
                // И обновляем его родителей
                $this->syncParentDates($successor);
            }
        }
    }

    /**
     * Рассчитать новую дату начала на основе зависимости
     */
    protected function calculateNewStartDate(ScheduleTask $predecessor, TaskDependency $dependency): ?Carbon
    {
        $lag = $dependency->lag_days ?? 0;
        $type = $dependency->dependency_type;

        return match ($type) {
            DependencyTypeEnum::FINISH_TO_START => Carbon::parse($predecessor->planned_end_date)->addDays($lag + 1),
            DependencyTypeEnum::START_TO_START => Carbon::parse($predecessor->planned_start_date)->addDays($lag),
            DependencyTypeEnum::FINISH_TO_FINISH => null, // Требует сложной логики расчета от конца, пока не реализуем каскадом
            DependencyTypeEnum::START_TO_FINISH => null,
            default => Carbon::parse($predecessor->planned_end_date)->addDays($lag + 1),
        };
    }

    /**
     * Пересчитать WBS коды для всего графика
     */
    public function recalculateWbsCodes(ProjectSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule) {
            $rootTasks = $schedule->rootTasks;
            foreach ($rootTasks as $index => $task) {
                $this->updateWbsRecursive($task, (string)($index + 1));
            }
        });
    }

    protected function updateWbsRecursive(ScheduleTask $task, string $prefix): void
    {
        $task->update(['wbs_code' => $prefix]);
        
        $children = $task->childTasks;
        foreach ($children as $index => $child) {
            $this->updateWbsRecursive($child, $prefix . '.' . ($index + 1));
        }
    }
}
