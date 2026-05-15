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
        $task = $this->resource;

        if (!$task instanceof LookaheadPlanTask) {
            return [];
        }

        return [
            'id' => $task->id,
            'lookahead_plan_id' => $task->lookahead_plan_id,
            'schedule_task_id' => $task->schedule_task_id,
            'planned_start_date' => $task->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $task->planned_end_date?->format('Y-m-d'),
            'planned_quantity' => $task->planned_quantity !== null ? (float) $task->planned_quantity : null,
            'planned_work_hours' => $task->planned_work_hours !== null ? (float) $task->planned_work_hours : null,
            'readiness_status' => $task->readiness_status,
            'schedule_task' => $task->relationLoaded('scheduleTask') && $task->scheduleTask ? [
                'id' => $task->scheduleTask->id,
                'name' => $task->scheduleTask->name,
                'status' => $task->scheduleTask->status?->value,
            ] : null,
            'constraints' => $task->relationLoaded('constraints') ? WorkConstraintResource::collection($task->constraints) : [],
        ];
    }
}
