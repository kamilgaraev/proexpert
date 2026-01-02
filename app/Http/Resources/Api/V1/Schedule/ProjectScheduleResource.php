<?php

namespace App\Http\Resources\Api\V1\Schedule;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'description' => $this->description,
            
            // Даты планирования
            'planned_start_date' => $this->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $this->planned_end_date?->format('Y-m-d'),
            'planned_duration_days' => $this->planned_duration_days,
            
            // Базовые даты
            'baseline_start_date' => $this->baseline_start_date?->format('Y-m-d'),
            'baseline_end_date' => $this->baseline_end_date?->format('Y-m-d'),
            'baseline_saved_at' => $this->baseline_saved_at?->format('Y-m-d H:i:s'),
            'baseline_saved_by' => $this->when($this->baselineSavedBy, [
                'id' => $this->baselineSavedBy?->id,
                'name' => $this->baselineSavedBy?->name,
                'email' => $this->baselineSavedBy?->email,
            ]),
            
            // Фактические даты
            'actual_start_date' => $this->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $this->actual_end_date?->format('Y-m-d'),
            'actual_duration_days' => $this->actual_duration_days,
            
            // Статус и прогресс
            'status' => $this->status->value ?? $this->status,
            'status_label' => $this->status->label ?? $this->status,
            'overall_progress_percent' => (float) $this->overall_progress_percent,
            'health_status' => $this->health_status,
            
            // Шаблон
            'is_template' => $this->is_template,
            'template_name' => $this->template_name,
            'template_description' => $this->template_description,
            
            // Критический путь
            'critical_path_calculated' => $this->critical_path_calculated,
            'critical_path_updated_at' => $this->critical_path_updated_at?->format('Y-m-d H:i:s'),
            'critical_path_duration_days' => $this->critical_path_duration_days,
            
            // Финансы
            'total_estimated_cost' => $this->total_estimated_cost ? (float) $this->total_estimated_cost : 0,
            'total_actual_cost' => $this->total_actual_cost ? (float) $this->total_actual_cost : 0,
            'cost_variance' => $this->cost_variance,
            'schedule_variance' => $this->schedule_variance,
            
            // Настройки
            'calculation_settings' => $this->calculation_settings ?? [],
            'display_settings' => $this->display_settings ?? [],
            
            // Связанные данные
            'project' => $this->when($this->relationLoaded('project'), [
                'id' => $this->project?->id,
                'name' => $this->project?->name,
                'description' => $this->project?->description,
                'status' => $this->project?->status,
            ]),
            
            'created_by' => $this->when($this->relationLoaded('createdBy'), [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
                'email' => $this->createdBy?->email,
            ]),
            
            // Статистика задач
            'tasks_count' => $this->when(isset($this->tasks_count), $this->tasks_count),
            'dependencies_count' => $this->when(isset($this->dependencies_count), $this->dependencies_count),
            'resources_count' => $this->when(isset($this->resources_count), $this->resources_count),
            
            // Задачи (если загружены)
            'tasks' => $this->when(
                $this->relationLoaded('tasks'),
                $this->tasks->map(function ($task) {
                    return [
                        'id' => $task->id,
                        'parent_task_id' => $task->parent_task_id,
                        'name' => $task->name,
                        'task_type' => $task->task_type,
                        'status' => $task->status,
                        'progress_percent' => $task->progress_percent,
                        'level' => $task->level,
                        'is_critical' => $task->is_critical,
                        
                        // Плановые даты
                        'planned_start_date' => $task->planned_start_date?->format('Y-m-d'),
                        'planned_end_date' => $task->planned_end_date?->format('Y-m-d'),
                        'planned_duration_days' => $task->planned_duration_days,
                        'planned_work_hours' => $task->planned_work_hours ? (float) $task->planned_work_hours : null,
                        'labor_hours_from_estimate' => $task->labor_hours_from_estimate ? (float) $task->labor_hours_from_estimate : null,
                        
                        // Фактические даты
                        'actual_start_date' => $task->actual_start_date?->format('Y-m-d'),
                        'actual_end_date' => $task->actual_end_date?->format('Y-m-d'),
                        'actual_duration_days' => $task->actual_duration_days,
                        
                        // Связь со сметой
                        'estimate_item_id' => $task->estimate_item_id,
                        'estimate_section_id' => $task->estimate_section_id,
                    ];
                })
            ),
            
            // Зависимости (если загружены)
            'dependencies' => $this->when(
                $this->relationLoaded('dependencies'),
                $this->dependencies->map(function ($dependency) {
                    return [
                        'id' => $dependency->id,
                        'predecessor_task_id' => $dependency->predecessor_task_id,
                        'successor_task_id' => $dependency->successor_task_id,
                        'dependency_type' => $dependency->dependency_type,
                        'is_critical' => $dependency->is_critical,
                    ];
                })
            ),
            
            // Ресурсы (если загружены)
            'resources' => $this->when(
                $this->relationLoaded('resources'),
                $this->resources->map(function ($resource) {
                    return [
                        'id' => $resource->id,
                        'resource_type' => $resource->resource_type,
                        'resource_name' => $resource->resource_name,
                        'allocation_percent' => $resource->allocation_percent,
                        'has_conflicts' => $resource->has_conflicts,
                    ];
                })
            ),
            
            // Вехи (если загружены)
            'milestones' => $this->when(
                $this->relationLoaded('milestones'),
                $this->milestones->map(function ($milestone) {
                    return [
                        'id' => $milestone->id,
                        'name' => $milestone->name,
                        'target_date' => $milestone->target_date?->format('Y-m-d'),
                        'status' => $milestone->status,
                        'is_critical' => $milestone->is_critical,
                    ];
                })
            ),
            
            // Временные метки
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'deleted_at' => $this->deleted_at?->format('Y-m-d H:i:s'),
            
            // Дополнительные вычисляемые поля для UI
            'ui_data' => [
                'can_edit' => $this->status === 'draft' || $this->status === 'active',
                'can_delete' => $this->status === 'draft' || ($this->status === 'active' && $this->overall_progress_percent == 0),
                'can_activate' => $this->status === 'draft',
                'can_complete' => $this->status === 'active' && $this->overall_progress_percent >= 100,
                'can_save_baseline' => $this->status === 'active' && !$this->baseline_saved_at,
                'can_clear_baseline' => (bool) $this->baseline_saved_at,
                'needs_critical_path_calculation' => !$this->critical_path_calculated || $this->needsCriticalPathRecalculation(),
                'progress_color' => $this->getProgressColor(),
                'status_color' => $this->getStatusColor(),
            ],
        ];
    }

    protected function getProgressColor(): string
    {
        $progress = (float) $this->overall_progress_percent;
        
        if ($progress < 25) return '#EF4444'; // красный
        if ($progress < 50) return '#F59E0B'; // оранжевый
        if ($progress < 75) return '#3B82F6'; // синий
        if ($progress < 100) return '#10B981'; // зеленый
        
        return '#059669'; // темно-зеленый (завершено)
    }

    protected function getStatusColor(): string
    {
        return match($this->status) {
            'draft' => '#6B7280',      // серый
            'active' => '#3B82F6',     // синий
            'paused' => '#F59E0B',     // оранжевый
            'completed' => '#10B981',  // зеленый
            'cancelled' => '#EF4444',  // красный
            default => '#6B7280',
        };
    }
} 