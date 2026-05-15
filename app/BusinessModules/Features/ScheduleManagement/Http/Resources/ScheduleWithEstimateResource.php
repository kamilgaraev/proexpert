<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

use App\Models\ProjectSchedule;
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
        $schedule = $this->resource;

        if (!$schedule instanceof ProjectSchedule) {
            return [];
        }

        return [
            'id' => $schedule->id,
            'organization_id' => $schedule->organization_id,
            'project_id' => $schedule->project_id,
            'name' => $schedule->name,
            'description' => $schedule->description,
            'status' => $schedule->status,
            
            // Даты графика
            'planned_start_date' => $schedule->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $schedule->planned_end_date?->format('Y-m-d'),
            'actual_start_date' => $schedule->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $schedule->actual_end_date?->format('Y-m-d'),
            
            // Прогресс и стоимость
            'overall_progress_percent' => (float) $schedule->overall_progress_percent,
            'total_estimated_cost' => (float) $schedule->total_estimated_cost,
            'total_actual_cost' => (float) $schedule->total_actual_cost,
            
            // Данные интеграции со сметой
            'estimate_integration' => [
                'estimate_id' => $schedule->estimate_id,
                'sync_with_estimate' => $schedule->sync_with_estimate,
                'sync_status' => $schedule->sync_status,
                'last_synced_at' => $schedule->last_synced_at?->toISOString(),
                'needs_sync' => $schedule->needsSync(),
            ],
            
            // Связанная смета (если загружена)
            'estimate' => $this->when($schedule->relationLoaded('estimate') && $schedule->estimate, function () use ($schedule) {
                return [
                    'id' => $schedule->estimate->id,
                    'number' => $schedule->estimate->number,
                    'name' => $schedule->estimate->name,
                    'status' => $schedule->estimate->status,
                    'total_amount' => (float) $schedule->estimate->total_amount,
                    'updated_at' => $schedule->estimate->updated_at?->toISOString(),
                ];
            }),
            
            // Задачи (если загружены)
            'tasks' => $this->when($schedule->relationLoaded('tasks'), function () use ($schedule) {
                return ScheduleTaskWithVolumeResource::collection($schedule->tasks);
            }),
            
            // Timestamps
            'created_at' => $schedule->created_at?->toISOString(),
            'updated_at' => $schedule->updated_at?->toISOString(),
        ];
    }
}

