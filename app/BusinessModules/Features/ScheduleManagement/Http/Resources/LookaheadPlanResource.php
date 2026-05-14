<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

use App\BusinessModules\Features\ScheduleManagement\Models\LookaheadPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LookaheadPlan */
final class LookaheadPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plan = $this->resource;

        if (!$plan instanceof LookaheadPlan) {
            return [];
        }

        return [
            'id' => $plan->id,
            'organization_id' => $plan->organization_id,
            'project_id' => $plan->project_id,
            'schedule_id' => $plan->schedule_id,
            'title' => $plan->title,
            'start_date' => $plan->start_date?->format('Y-m-d'),
            'end_date' => $plan->end_date?->format('Y-m-d'),
            'status' => $plan->status,
            'workflow_summary' => [
                'status' => $plan->status,
                'status_label' => trans_message("schedule_management.lookahead_statuses.{$plan->status}"),
                'available_actions' => $plan->status === 'draft' ? ['add_task', 'create_daily_plan'] : [],
                'problem_flags' => [],
            ],
            'tasks' => $plan->relationLoaded('tasks') ? LookaheadPlanTaskResource::collection($plan->tasks) : [],
            'daily_plans' => $plan->relationLoaded('dailyPlans') ? DailyWorkPlanResource::collection($plan->dailyPlans) : [],
            'created_at' => $plan->created_at?->toIso8601String(),
        ];
    }
}
