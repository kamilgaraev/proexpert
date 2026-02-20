<?php

namespace App\Http\Resources\Api\V1\Schedule;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleTaskResource extends JsonResource
{
    /**
     * Стандартный ресурс для задачи графика
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_id' => $this->schedule_id,
            'organization_id' => $this->organization_id,
            'parent_task_id' => $this->parent_task_id,
            'name' => $this->name,
            'description' => $this->description,
            'wbs_code' => $this->wbs_code,
            'task_type' => $this->task_type->value ?? $this->task_type,
            'level' => $this->level ?? 0,
            'sort_order' => $this->sort_order ?? 0,
            
            // Плановые даты и длительность
            'planned_start_date' => $this->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $this->planned_end_date?->format('Y-m-d'),
            'planned_duration_days' => $this->planned_duration_days,
            'planned_work_hours' => $this->planned_work_hours ? (float) $this->planned_work_hours : 0,
            
            // Фактические данные
            'actual_start_date' => $this->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $this->actual_end_date?->format('Y-m-d'),
            'actual_duration_days' => $this->actual_duration_days,
            'actual_work_hours' => $this->actual_work_hours ? (float) $this->actual_work_hours : 0,
            
            // Прогресс и статус
            'progress_percent' => (float) ($this->progress_percent ?? 0),
            'status' => $this->status->value ?? $this->status,
            'status_label' => $this->status->label ?? $this->status,
            'priority' => $this->priority->value ?? $this->priority,
            
            // Стоимость и объемы
            'estimated_cost' => (float) ($this->estimated_cost ?? 0),
            'actual_cost' => (float) ($this->actual_cost ?? 0),
            'quantity' => (float) ($this->quantity ?? 0),
            'measurement_unit_id' => $this->measurement_unit_id,
            'resource_cost' => (float) ($this->resource_cost ?? 0),
            'labor_hours_from_estimate' => (float) ($this->labor_hours_from_estimate ?? 0),
            
            // Критический путь и ограничения
            'is_critical' => $this->is_critical ?? false,
            'is_overdue' => $this->is_overdue ?? false,
            'early_start_date' => $this->early_start_date?->format('Y-m-d'),
            'early_finish_date' => $this->early_finish_date?->format('Y-m-d'),
            'late_start_date' => $this->late_start_date?->format('Y-m-d'),
            'late_finish_date' => $this->late_finish_date?->format('Y-m-d'),
            'total_float_days' => $this->total_float_days ?? 0,
            'free_float_days' => $this->free_float_days ?? 0,
            'constraint_type' => $this->constraint_type,
            'constraint_date' => $this->constraint_date?->format('Y-m-d'),
            
            // Связи
            'estimate_item_id' => $this->estimate_item_id,
            'estimate_section_id' => $this->estimate_section_id,
            'assigned_user' => $this->when($this->relationLoaded('assignedUser'), [
                'id' => $this->assignedUser?->id,
                'name' => $this->assignedUser?->name,
            ]),
            'work_type' => $this->when($this->relationLoaded('workType'), [
                'id' => $this->workType?->id,
                'name' => $this->workType?->name,
            ]),
            'measurement_unit' => $this->when($this->relationLoaded('measurementUnit'), [
                'id' => $this->measurementUnit?->id,
                'name' => $this->measurementUnit?->name,
                'short_name' => $this->measurementUnit?->short_name,
            ]),
            'parent_task' => $this->when($this->relationLoaded('parentTask'), [
                'id' => $this->parentTask?->id,
                'name' => $this->parentTask?->name,
            ]),
            
            // Временные метки
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
