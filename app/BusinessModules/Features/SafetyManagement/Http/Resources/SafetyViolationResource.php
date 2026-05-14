<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SafetyViolation */
final class SafetyViolationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SafetyViolation $violation */
        $violation = $this->resource;
        $actions = $violation->status === 'open' ? ['resolve'] : [];

        return [
            'id' => $violation->id,
            'organization_id' => $violation->organization_id,
            'project_id' => $violation->project_id,
            'created_by_user_id' => $violation->created_by_user_id,
            'assigned_to_user_id' => $violation->assigned_to_user_id,
            'resolved_by_user_id' => $violation->resolved_by_user_id,
            'violation_number' => $violation->violation_number,
            'title' => $violation->title,
            'severity' => $violation->severity,
            'status' => $violation->status,
            'status_label' => trans_message("safety_management.violation_statuses.{$violation->status}"),
            'location_name' => $violation->location_name,
            'description' => $violation->description,
            'corrective_action' => $violation->corrective_action,
            'due_date' => $violation->due_date?->format('Y-m-d'),
            'resolved_at' => $violation->resolved_at?->toIso8601String(),
            'resolution_comment' => $violation->resolution_comment,
            'workflow_summary' => [
                'status' => $violation->status,
                'stage_label' => trans_message("safety_management.violation_statuses.{$violation->status}"),
                'available_actions' => $actions,
                'blocked' => false,
            ],
            'problem_flags' => $this->problemFlags($violation),
            'available_actions' => $actions,
            'project' => $this->whenLoaded('project', fn () => $violation->project ? [
                'id' => $violation->project->id,
                'name' => $violation->project->name,
            ] : null),
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $violation->assignedUser ? [
                'id' => $violation->assignedUser->id,
                'name' => $violation->assignedUser->name,
            ] : null),
            'metadata' => $violation->metadata,
            'created_at' => $violation->created_at?->toIso8601String(),
            'updated_at' => $violation->updated_at?->toIso8601String(),
        ];
    }

    private function problemFlags(SafetyViolation $violation): array
    {
        if ($violation->status === 'open' && $violation->due_date !== null && $violation->due_date->isPast()) {
            return [[
                'code' => 'violation_overdue',
                'severity' => 'critical',
                'message' => trans_message('safety_management.problem_flags.violation_overdue'),
            ]];
        }

        return [];
    }
}
