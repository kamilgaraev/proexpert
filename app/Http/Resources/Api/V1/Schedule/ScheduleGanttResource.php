<?php

namespace App\Http\Resources\Api\V1\Schedule;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleGanttResource extends JsonResource
{
    /**
     * Resource для полного графика работ в формате Gantt-диаграммы
     * Включает все задачи с иерархией, зависимости, даты для отрисовки
     */
    public function toArray(Request $request): array
    {
        // Получаем корневые задачи с полной иерархией
        $rootTasks = $this->rootTasks ?? $this->whenLoaded('rootTasks', $this->rootTasks);
        
        // Если rootTasks не загружены, загружаем задачи и строим дерево
        if (!$rootTasks) {
            $rootTasks = $this->tasks()->whereNull('parent_task_id')->orderBy('sort_order')->get();
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            
            // Даты графика
            'planned_start_date' => $this->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $this->planned_end_date?->format('Y-m-d'),
            'actual_start_date' => $this->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $this->actual_end_date?->format('Y-m-d'),
            
            // Прогресс
            'overall_progress_percent' => (float) ($this->overall_progress_percent ?? 0),
            'status' => $this->status->value ?? $this->status,
            
            // Иерархия задач для Gantt
            'tasks' => ScheduleTaskGanttResource::collection($rootTasks),
            
            // Все зависимости для отрисовки линий
            'dependencies' => $this->when(
                $this->relationLoaded('dependencies'),
                $this->dependencies->map(function ($dependency) {
                    return [
                        'id' => $dependency->id,
                        'predecessor_task_id' => $dependency->predecessor_task_id,
                        'successor_task_id' => $dependency->successor_task_id,
                        'type' => $dependency->dependency_type->value ?? $dependency->dependency_type,
                        'type_label' => $dependency->dependency_type->label() ?? '',
                        'lag_days' => $dependency->lag_days ?? 0,
                        'is_critical' => $dependency->is_critical ?? false,
                        'is_active' => $dependency->is_active ?? true,
                        // Координаты для отрисовки линии
                        'gantt_line' => $this->getDependencyLineCoordinates($dependency),
                    ];
                })
            ),
            
            // Метаданные для UI
            'gantt_meta' => [
                'date_range' => $this->getDateRange(),
                'current_date' => now()->format('Y-m-d'),
                'view_mode' => 'days', // days, weeks, months
                'timeline' => $this->generateTimeline(),
                'critical_path_tasks' => $this->tasks()
                    ->where('is_critical', true)
                    ->pluck('id')
                    ->toArray(),
            ],
            
            // Настройки отображения
            'display_settings' => $this->display_settings ?? [
                'show_critical_path' => true,
                'show_float' => false,
                'show_baseline' => false,
                'show_actual_progress' => true,
            ],
        ];
    }

    /**
     * Получить диапазон дат для временной шкалы
     */
    protected function getDateRange(): array
    {
        $allTasks = $this->tasks;
        
        if ($allTasks->isEmpty()) {
            return [
                'start' => $this->planned_start_date?->format('Y-m-d') ?? now()->format('Y-m-d'),
                'end' => $this->planned_end_date?->format('Y-m-d') ?? now()->addMonths(3)->format('Y-m-d'),
            ];
        }

        $minDate = $allTasks->min(function ($task) {
            return $task->planned_start_date ?? $task->actual_start_date ?? $task->early_start_date;
        });

        $maxDate = $allTasks->max(function ($task) {
            return $task->planned_end_date ?? $task->actual_end_date ?? $task->late_finish_date;
        });

        return [
            'start' => $minDate?->format('Y-m-d') ?? $this->planned_start_date?->format('Y-m-d'),
            'end' => $maxDate?->format('Y-m-d') ?? $this->planned_end_date?->format('Y-m-d'),
        ];
    }

    /**
     * Генерировать массив дат для временной шкалы
     */
    protected function generateTimeline(): array
    {
        $dateRange = $this->getDateRange();
        $start = \Carbon\Carbon::parse($dateRange['start']);
        $end = \Carbon\Carbon::parse($dateRange['end']);
        
        $timeline = [];
        $current = $start->copy();
        
        while ($current <= $end) {
            $timeline[] = [
                'date' => $current->format('Y-m-d'),
                'day' => $current->day,
                'month' => $current->month,
                'year' => $current->year,
                'month_name' => $current->locale('ru')->monthName,
                'is_weekend' => $current->isWeekend(),
                'is_today' => $current->isToday(),
            ];
            $current->addDay();
        }
        
        return $timeline;
    }

    /**
     * Получить координаты для отрисовки линии зависимости
     */
    protected function getDependencyLineCoordinates($dependency): ?array
    {
        $predecessor = $this->tasks->firstWhere('id', $dependency->predecessor_task_id);
        $successor = $this->tasks->firstWhere('id', $dependency->successor_task_id);
        
        if (!$predecessor || !$successor) {
            return null;
        }

        // Используем фактическую дату окончания предшественника или плановую
        $predEnd = $predecessor->actual_end_date ?? $predecessor->planned_end_date ?? $predecessor->early_finish_date;
        $succStart = $successor->actual_start_date ?? $successor->planned_start_date ?? $successor->early_start_date;
        
        if (!$predEnd || !$succStart) {
            return null;
        }

        return [
            'from' => [
                'task_id' => $dependency->predecessor_task_id,
                'date' => $predEnd->format('Y-m-d'),
            ],
            'to' => [
                'task_id' => $dependency->successor_task_id,
                'date' => $succStart->format('Y-m-d'),
            ],
            'lag_days' => $dependency->lag_days ?? 0,
        ];
    }
}

