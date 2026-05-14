<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

use App\BusinessModules\Features\ScheduleManagement\Models\LookaheadPlanTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LookaheadPlanTask */
final class LookaheadPlanTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lookahead_plan_id' => $this->lookahead_plan_id,
            'schedule_task_id' => $this->schedule_task_id,
            'planned_start_date' => $this->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $this->planned_end_date?->format('Y-m-d'),
            'planned_quantity' => $this->planned_quantity !== null ? (float) $this->planned_quantity : null,
            'planned_work_hours' => $this->planned_work_hours !== null ? (float) $this->planned_work_hours : null,
            'readiness_status' => $this->readiness_status,
            'schedule_task' => $this->whenLoaded('scheduleTask', fn () => $this->scheduleTask ? [
                'id' => $this->scheduleTask->id,
                'name' => $this->scheduleTask->name,
                'status' => $this->scheduleTask->status?->value,
            ] : null),
            'constraints' => WorkConstraintResource::collection($this->whenLoaded('constraints')),
        ];
    }
}
