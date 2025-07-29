<?php

namespace App\Http\Resources\Api\V1\Schedule;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ProjectScheduleCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($schedule) {
                return [
                    'id' => $schedule->id,
                    'project_id' => $schedule->project_id,
                    'name' => $schedule->name,
                    'description' => $schedule->description,
                    
                    // Основные даты
                    'planned_start_date' => $schedule->planned_start_date?->format('Y-m-d'),
                    'planned_end_date' => $schedule->planned_end_date?->format('Y-m-d'),
                    'planned_duration_days' => $schedule->planned_duration_days,
                    
                    // Статус и прогресс
                    'status' => $schedule->status->value ?? $schedule->status,
                    'status_label' => $schedule->status->label ?? $schedule->status,
                    'overall_progress_percent' => (float) $schedule->overall_progress_percent,
                    'health_status' => $schedule->health_status,
                    
                    // Шаблон
                    'is_template' => $schedule->is_template,
                    'template_name' => $schedule->template_name,
                    
                    // Критический путь
                    'critical_path_calculated' => $schedule->critical_path_calculated,
                    'critical_path_duration_days' => $schedule->critical_path_duration_days,
                    
                    // Финансы
                    'total_estimated_cost' => $schedule->total_estimated_cost ? (float) $schedule->total_estimated_cost : 0,
                    'total_actual_cost' => $schedule->total_actual_cost ? (float) $schedule->total_actual_cost : 0,
                    'cost_variance' => $schedule->cost_variance,
                    
                    // Связанные данные
                    'project' => $schedule->relationLoaded('project') ? [
                        'id' => $schedule->project?->id,
                        'name' => $schedule->project?->name,
                        'status' => $schedule->project?->status,
                    ] : null,
                    
                    'created_by' => $schedule->relationLoaded('createdBy') ? [
                        'id' => $schedule->createdBy?->id,
                        'name' => $schedule->createdBy?->name,
                    ] : null,
                    
                    // Счетчики
                    'tasks_count' => $schedule->tasks_count ?? 0,
                    'dependencies_count' => $schedule->dependencies_count ?? 0,
                    'resources_count' => $schedule->resources_count ?? 0,
                    
                    // Временные метки
                    'created_at' => $schedule->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $schedule->updated_at?->format('Y-m-d H:i:s'),
                    
                    // UI данные
                    'ui_data' => [
                        'can_edit' => $schedule->status === 'draft' || $schedule->status === 'active',
                        'can_delete' => $schedule->status === 'draft' || ($schedule->status === 'active' && $schedule->overall_progress_percent == 0),
                        'progress_color' => $this->getProgressColor($schedule->overall_progress_percent),
                        'status_color' => $this->getStatusColor($schedule->status),
                    ],
                ];
            }),
            
            // Метаданные пагинации
            'meta' => [
                'current_page' => $this->currentPage(),
                'from' => $this->firstItem(),
                'last_page' => $this->lastPage(),
                'path' => $this->path(),
                'per_page' => $this->perPage(),
                'to' => $this->lastItem(),
                'total' => $this->total(),
            ],
            
            'links' => [
                'first' => $this->url(1),
                'last' => $this->url($this->lastPage()),
                'prev' => $this->previousPageUrl(),
                'next' => $this->nextPageUrl(),
            ],
        ];
    }

    protected function getProgressColor(float $progress): string
    {
        if ($progress < 25) return '#EF4444'; // красный
        if ($progress < 50) return '#F59E0B'; // оранжевый
        if ($progress < 75) return '#3B82F6'; // синий
        if ($progress < 100) return '#10B981'; // зеленый
        
        return '#059669'; // темно-зеленый (завершено)
    }

    protected function getStatusColor($status): string
    {
        $statusValue = is_object($status) ? $status->value : $status;
        
        return match($statusValue) {
            'draft' => '#6B7280',      // серый
            'active' => '#3B82F6',     // синий
            'paused' => '#F59E0B',     // оранжевый
            'completed' => '#10B981',  // зеленый
            'cancelled' => '#EF4444',  // красный
            default => '#6B7280',
        };
    }
} 