<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

use App\Models\ScheduleTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleTaskWithVolumeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $task = $this->resource;

        if (!$task instanceof ScheduleTask) {
            return [];
        }

        return [
            'id' => $task->id,
            'schedule_id' => $task->schedule_id,
            'parent_task_id' => $task->parent_task_id,
            'name' => $task->name,
            'description' => $task->description,
            'wbs_code' => $task->wbs_code,
            'task_type' => $task->task_type,
            'status' => $task->status,
            'priority' => $task->priority,
            
            // Даты и длительность
            'planned_start_date' => $task->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $task->planned_end_date?->format('Y-m-d'),
            'planned_duration_days' => $task->planned_duration_days,
            'actual_start_date' => $task->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $task->actual_end_date?->format('Y-m-d'),
            
            // Прогресс
            'progress_percent' => (float) $task->progress_percent,
            
            // Трудозатраты
            'planned_work_hours' => (float) $task->planned_work_hours,
            'actual_work_hours' => (float) $task->actual_work_hours,
            'labor_hours_from_estimate' => (float) ($task->labor_hours_from_estimate ?? 0),
            
            // Объемы из сметы
            'work_volume' => [
                'quantity' => (float) ($task->quantity ?? 0),
                'unit' => $this->when($task->relationLoaded('measurementUnit') && $task->measurementUnit, function () use ($task) {
                    return [
                        'id' => $task->measurementUnit->id,
                        'name' => $task->measurementUnit->name,
                        'short_name' => $task->measurementUnit->short_name,
                    ];
                }),
                'formatted' => $task->work_volume,
            ],
            
            // Стоимость
            'estimated_cost' => (float) ($task->estimated_cost ?? 0),
            'actual_cost' => (float) ($task->actual_cost ?? 0),
            'resource_cost' => (float) ($task->resource_cost ?? 0),
            
            // Связь со сметой
            'estimate_integration' => [
                'estimate_item_id' => $task->estimate_item_id,
                'estimate_section_id' => $task->estimate_section_id,
                'is_linked_to_estimate' => !is_null($task->estimate_item_id),
            ],
            
            // Исполнители
            'assigned_user' => $this->when($task->relationLoaded('assignedUser') && $task->assignedUser, function () use ($task) {
                return [
                    'id' => $task->assignedUser->id,
                    'name' => $task->assignedUser->name,
                ];
            }),
            
            // Сортировка и иерархия
            'level' => $task->level,
            'sort_order' => $task->sort_order,
            
            // Timestamps
            'created_at' => $task->created_at?->toISOString(),
            'updated_at' => $task->updated_at?->toISOString(),
        ];
    }
}

