<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SafetyCorrectiveAction */
final class SafetyCorrectiveActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SafetyCorrectiveAction $action */
        $action = $this->resource;
        $actions = match ($action->status) {
            'open' => ['resolve'],
            'resolved' => ['verify'],
            default => [],
        };

        return [
            'id' => $action->id,
            'organization_id' => $action->organization_id,
            'project_id' => $action->project_id,
            'incident_id' => $action->incident_id,
            'violation_id' => $action->violation_id,
            'created_by_user_id' => $action->created_by_user_id,
            'assigned_to_user_id' => $action->assigned_to_user_id,
            'resolved_by_user_id' => $action->resolved_by_user_id,
            'verified_by_user_id' => $action->verified_by_user_id,
            'action_number' => $action->action_number,
            'title' => $action->title,
            'description' => $action->description,
            'source_type' => $action->source_type,
            'severity' => $action->severity,
            'status' => $action->status,
            'status_label' => trans_message("safety_management.corrective_action_statuses.{$action->status}"),
            'due_date' => $action->due_date?->format('Y-m-d'),
            'resolution_comment' => $action->resolution_comment,
            'resolved_at' => $action->resolved_at?->toIso8601String(),
            'verification_comment' => $action->verification_comment,
            'verified_at' => $action->verified_at?->toIso8601String(),
            'workflow_summary' => [
                'status' => $action->status,
                'stage_label' => trans_message("safety_management.corrective_action_statuses.{$action->status}"),
                'available_actions' => $actions,
                'blocked' => false,
            ],
            'available_actions' => $actions,
            'project' => $this->whenLoaded('project', fn () => $action->project ? [
                'id' => $action->project->id,
                'name' => $action->project->name,
            ] : null),
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $action->assignedUser ? [
                'id' => $action->assignedUser->id,
                'name' => $action->assignedUser->name,
            ] : null),
            'metadata' => $action->metadata,
            'created_at' => $action->created_at?->toIso8601String(),
            'updated_at' => $action->updated_at?->toIso8601String(),
        ];
    }
}
