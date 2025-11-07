<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

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
        return [
            'id' => $this->id,
            'schedule_id' => $this->schedule_id,
            'parent_task_id' => $this->parent_task_id,
            'name' => $this->name,
            'description' => $this->description,
            'wbs_code' => $this->wbs_code,
            'task_type' => $this->task_type,
            'status' => $this->status,
            'priority' => $this->priority,
            
            // Даты и длительность
            'planned_start_date' => $this->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $this->planned_end_date?->format('Y-m-d'),
            'planned_duration_days' => $this->planned_duration_days,
            'actual_start_date' => $this->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $this->actual_end_date?->format('Y-m-d'),
            
            // Прогресс
            'progress_percent' => (float) $this->progress_percent,
            
            // Трудозатраты
            'planned_work_hours' => (float) $this->planned_work_hours,
            'actual_work_hours' => (float) $this->actual_work_hours,
            'labor_hours_from_estimate' => (float) ($this->labor_hours_from_estimate ?? 0),
            
            // Объемы из сметы
            'work_volume' => [
                'quantity' => (float) ($this->quantity ?? 0),
                'unit' => $this->when($this->relationLoaded('measurementUnit') && $this->measurementUnit, function () {
                    return [
                        'id' => $this->measurementUnit->id,
                        'name' => $this->measurementUnit->name,
                        'short_name' => $this->measurementUnit->short_name,
                    ];
                }),
                'formatted' => $this->work_volume,
            ],
            
            // Стоимость
            'estimated_cost' => (float) ($this->estimated_cost ?? 0),
            'actual_cost' => (float) ($this->actual_cost ?? 0),
            'resource_cost' => (float) ($this->resource_cost ?? 0),
            
            // Связь со сметой
            'estimate_integration' => [
                'estimate_item_id' => $this->estimate_item_id,
                'estimate_section_id' => $this->estimate_section_id,
                'is_linked_to_estimate' => !is_null($this->estimate_item_id),
            ],
            
            // Исполнители
            'assigned_user' => $this->when($this->relationLoaded('assignedUser') && $this->assignedUser, function () {
                return [
                    'id' => $this->assignedUser->id,
                    'name' => $this->assignedUser->name,
                ];
            }),
            
            // Сортировка и иерархия
            'level' => $this->level,
            'sort_order' => $this->sort_order,
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

