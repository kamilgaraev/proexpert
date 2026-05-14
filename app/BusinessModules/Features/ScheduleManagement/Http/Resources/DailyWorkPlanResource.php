<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

use App\BusinessModules\Features\ScheduleManagement\Models\DailyWorkPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DailyWorkPlan */
final class DailyWorkPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $daily = $this->resource;

        if (!$daily instanceof DailyWorkPlan) {
            return [];
        }

        $problemFlags = collect($daily->relationLoaded('assignments') ? $daily->assignments : [])
            ->flatMap(fn ($assignment) => $assignment->lookaheadPlanTask?->constraints ?? [])
            ->filter(fn ($constraint): bool => $constraint->severity === 'hard' && $constraint->overridden_at !== null)
            ->map(fn ($constraint): array => [
                'key' => 'hard_constraint_overridden',
                'label' => trans_message('schedule_management.problem_flags.hard_constraint_overridden'),
                'severity' => 'warning',
                'constraint_id' => $constraint->id,
            ])
            ->values()
            ->all();

        return [
            'id' => $daily->id,
            'organization_id' => $daily->organization_id,
            'project_id' => $daily->project_id,
            'schedule_id' => $daily->schedule_id,
            'lookahead_plan_id' => $daily->lookahead_plan_id,
            'work_date' => $daily->work_date?->format('Y-m-d'),
            'status' => $daily->status,
            'workflow_summary' => [
                'status' => $daily->status,
                'status_label' => trans_message("schedule_management.daily_plan_statuses.{$daily->status}"),
                'available_actions' => match ($daily->status) {
                    'draft' => ['publish'],
                    'published', 'in_progress' => ['submit', 'revise'],
                    'returned' => ['record_fact', 'submit', 'revise'],
                    'submitted' => ['accept', 'return'],
                    'accepted' => ['close'],
                    'closed' => ['revise'],
                    default => [],
                },
                'problem_flags' => $problemFlags,
            ],
            'assignments' => DailyWorkPlanAssignmentResource::collection($this->whenLoaded('assignments')),
            'published_at' => $daily->published_at?->toIso8601String(),
            'submitted_at' => $daily->submitted_at?->toIso8601String(),
            'accepted_at' => $daily->accepted_at?->toIso8601String(),
            'accepted_by_user_id' => $daily->accepted_by_user_id,
            'returned_at' => $daily->returned_at?->toIso8601String(),
            'returned_by_user_id' => $daily->returned_by_user_id,
            'return_reason' => $daily->return_reason,
            'closed_at' => $daily->closed_at?->toIso8601String(),
            'closed_by_user_id' => $daily->closed_by_user_id,
            'revision_of_daily_plan_id' => $daily->revision_of_daily_plan_id,
            'revision_number' => $daily->revision_number,
            'revised_at' => $daily->revised_at?->toIso8601String(),
            'revised_by_user_id' => $daily->revised_by_user_id,
            'revision_reason' => $daily->revision_reason,
        ];
    }
}
