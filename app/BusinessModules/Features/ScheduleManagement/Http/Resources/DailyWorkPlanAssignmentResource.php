<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

use App\BusinessModules\Features\ScheduleManagement\Models\DailyWorkPlanAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DailyWorkPlanAssignment */
final class DailyWorkPlanAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignment = $this->resource;

        if (!$assignment instanceof DailyWorkPlanAssignment) {
            return [];
        }

        return [
            'id' => $assignment->id,
            'daily_work_plan_id' => $assignment->daily_work_plan_id,
            'lookahead_plan_task_id' => $assignment->lookahead_plan_task_id,
            'schedule_task_id' => $assignment->schedule_task_id,
            'journal_entry_id' => $assignment->journal_entry_id,
            'assigned_user_id' => $assignment->assigned_user_id,
            'planned_quantity' => $assignment->planned_quantity !== null ? (float) $assignment->planned_quantity : null,
            'completed_quantity' => $assignment->completed_quantity !== null ? (float) $assignment->completed_quantity : null,
            'planned_work_hours' => $assignment->planned_work_hours !== null ? (float) $assignment->planned_work_hours : null,
            'actual_work_hours' => $assignment->actual_work_hours !== null ? (float) $assignment->actual_work_hours : null,
            'status' => $assignment->status,
            'failure_reason' => $assignment->failure_reason,
            'fact_comment' => $assignment->fact_comment,
            'linked_blocking_entities' => $assignment->metadata['linked_blocking_entities'] ?? [],
            'schedule_task' => $assignment->relationLoaded('scheduleTask') && $assignment->scheduleTask ? [
                'id' => $assignment->scheduleTask->id,
                'name' => $assignment->scheduleTask->name,
            ] : null,
        ];
    }
}
