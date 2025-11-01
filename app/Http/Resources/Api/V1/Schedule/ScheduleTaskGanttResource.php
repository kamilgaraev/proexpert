<?php

namespace App\Http\Resources\Api\V1\Schedule;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleTaskGanttResource extends JsonResource
{
    /**
     * Преобразует задачу для отображения в Gantt-диаграмме
     * Учитывает иерархию, зависимости, прогресс и даты
     */
    public function toArray(Request $request): array
    {
        $plannedDuration = $this->planned_start_date && $this->planned_end_date 
            ? $this->planned_start_date->diffInDays($this->planned_end_date) + 1 
            : 0;

        $actualDuration = $this->actual_start_date && $this->actual_end_date
            ? $this->actual_start_date->diffInDays($this->actual_end_date) + 1
            : null;

        // Вычисляем отклонение от плана в днях
        $scheduleVarianceDays = null;
        if ($this->actual_end_date && $this->planned_end_date) {
            $scheduleVarianceDays = $this->actual_end_date->diffInDays($this->planned_end_date);
            if ($this->actual_end_date > $this->planned_end_date) {
                $scheduleVarianceDays = '+' . $scheduleVarianceDays . ' д';
            } else {
                $scheduleVarianceDays = '-' . $scheduleVarianceDays . ' д';
            }
        }

        // Данные для отображения на временной шкале
        $ganttData = [
            // Базовая информация
            'id' => $this->id,
            'name' => $this->name,
            'wbs_code' => $this->wbs_code,
            'level' => $this->level ?? 0,
            'sort_order' => $this->sort_order ?? 0,
            
            // Иерархия
            'parent_task_id' => $this->parent_task_id,
            'has_children' => $this->relationLoaded('childTasks') 
                ? $this->childTasks->isNotEmpty() 
                : false,
            'children' => $this->when(
                $this->relationLoaded('childTasks') && $this->childTasks->isNotEmpty(),
                ScheduleTaskGanttResource::collection($this->childTasks)
            ),
            
            // Статус и прогресс
            'status' => $this->status->value ?? $this->status,
            'status_label' => $this->status->label ?? $this->status,
            'progress_percent' => (float) ($this->progress_percent ?? 0),
            'task_type' => $this->task_type->value ?? $this->task_type,
            
            // Плановые даты
            'planned_start_date' => $this->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $this->planned_end_date?->format('Y-m-d'),
            'planned_duration_days' => $plannedDuration,
            
            // Фактические даты
            'actual_start_date' => $this->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $this->actual_end_date?->format('Y-m-d'),
            'actual_duration_days' => $actualDuration,
            
            // Расчетные даты (критический путь)
            'early_start_date' => $this->early_start_date?->format('Y-m-d'),
            'early_finish_date' => $this->early_finish_date?->format('Y-m-d'),
            'late_start_date' => $this->late_start_date?->format('Y-m-d'),
            'late_finish_date' => $this->late_finish_date?->format('Y-m-d'),
            'total_float_days' => $this->total_float_days ?? 0,
            'free_float_days' => $this->free_float_days ?? 0,
            
            // Отклонения
            'schedule_variance_days' => $scheduleVarianceDays,
            'is_critical' => $this->is_critical ?? false,
            'is_overdue' => $this->is_overdue ?? false,
            
            // Для визуализации в Gantt
            'gantt_bar' => [
                // Плановый период (серая/фиолетовая полоса)
                'planned' => [
                    'start' => $this->planned_start_date?->format('Y-m-d'),
                    'end' => $this->planned_end_date?->format('Y-m-d'),
                    'duration' => $plannedDuration,
                ],
                // Фактический прогресс (синяя полоса)
                'actual' => $this->actual_start_date && $this->actual_end_date ? [
                    'start' => $this->actual_start_date?->format('Y-m-d'),
                    'end' => $this->actual_end_date?->format('Y-m-d'),
                    'duration' => $actualDuration,
                    'progress_percent' => (float) ($this->progress_percent ?? 0),
                ] : null,
                // Baseline (если есть)
                'baseline' => $this->baseline_start_date && $this->baseline_end_date ? [
                    'start' => $this->baseline_start_date?->format('Y-m-d'),
                    'end' => $this->baseline_end_date?->format('Y-m-d'),
                ] : null,
            ],
            
            // Зависимости (для отрисовки линий)
            'dependencies' => $this->when(
                $this->relationLoaded('predecessorDependencies'),
                $this->predecessorDependencies->map(function ($dependency) {
                    return [
                        'id' => $dependency->id,
                        'predecessor_task_id' => $dependency->predecessor_task_id,
                        'successor_task_id' => $dependency->successor_task_id,
                        'type' => $dependency->dependency_type->value ?? $dependency->dependency_type,
                        'lag_days' => $dependency->lag_days ?? 0,
                        'is_critical' => $dependency->is_critical ?? false,
                    ];
                })
            ),
            
            // Дополнительная информация
            'work_type' => $this->when($this->relationLoaded('workType'), [
                'id' => $this->workType?->id,
                'name' => $this->workType?->name,
            ]),
            'assigned_user' => $this->when($this->relationLoaded('assignedUser'), [
                'id' => $this->assignedUser?->id,
                'name' => $this->assignedUser?->name,
            ]),
            'priority' => $this->priority->value ?? $this->priority,
            
            // UI метаданные
            'ui' => [
                'is_expanded' => true,
                'is_visible' => true,
                'bar_color' => $this->getBarColor(),
                'progress_color' => $this->getProgressColor(),
                'text_color' => $this->is_critical ? '#DC2626' : '#1F2937',
            ],
        ];

        return $ganttData;
    }

    protected function getBarColor(): string
    {
        if ($this->is_critical) {
            return '#DC2626'; // Красный для критических задач
        }
        
        if ($this->task_type->value === 'milestone') {
            return '#10B981'; // Зеленый для вех
        }
        
        if ($this->task_type->value === 'summary' || $this->task_type->value === 'container') {
            return '#6B7280'; // Серый для контейнеров
        }
        
        return '#8B5CF6'; // Фиолетовый для обычных задач
    }

    protected function getProgressColor(): string
    {
        $progress = (float) ($this->progress_percent ?? 0);
        
        if ($progress === 100) {
            return '#059669'; // Темно-зеленый для завершенных
        }
        
        if ($progress >= 75) {
            return '#10B981'; // Зеленый
        }
        
        if ($progress >= 50) {
            return '#3B82F6'; // Синий
        }
        
        if ($progress >= 25) {
            return '#F59E0B'; // Оранжевый
        }
        
        return '#EF4444'; // Красный
    }
}

