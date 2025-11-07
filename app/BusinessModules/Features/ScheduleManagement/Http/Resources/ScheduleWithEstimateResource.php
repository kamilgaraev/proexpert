<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleWithEstimateResource extends JsonResource
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
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            
            // Даты графика
            'planned_start_date' => $this->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $this->planned_end_date?->format('Y-m-d'),
            'actual_start_date' => $this->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $this->actual_end_date?->format('Y-m-d'),
            
            // Прогресс и стоимость
            'overall_progress_percent' => (float) $this->overall_progress_percent,
            'total_estimated_cost' => (float) $this->total_estimated_cost,
            'total_actual_cost' => (float) $this->total_actual_cost,
            
            // Данные интеграции со сметой
            'estimate_integration' => [
                'estimate_id' => $this->estimate_id,
                'sync_with_estimate' => $this->sync_with_estimate,
                'sync_status' => $this->sync_status,
                'last_synced_at' => $this->last_synced_at?->toISOString(),
                'needs_sync' => $this->needsSync(),
            ],
            
            // Связанная смета (если загружена)
            'estimate' => $this->when($this->relationLoaded('estimate') && $this->estimate, function () {
                return [
                    'id' => $this->estimate->id,
                    'number' => $this->estimate->number,
                    'name' => $this->estimate->name,
                    'status' => $this->estimate->status,
                    'total_amount' => (float) $this->estimate->total_amount,
                    'updated_at' => $this->estimate->updated_at?->toISOString(),
                ];
            }),
            
            // Задачи (если загружены)
            'tasks' => $this->when($this->relationLoaded('tasks'), function () {
                return ScheduleTaskWithVolumeResource::collection($this->tasks);
            }),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

