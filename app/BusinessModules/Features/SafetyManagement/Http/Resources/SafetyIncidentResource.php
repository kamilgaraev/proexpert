<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SafetyIncident */
final class SafetyIncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SafetyIncident $incident */
        $incident = $this->resource;
        $actions = match ($incident->status) {
            'reported' => ['triage', 'cancel'],
            'triage' => ['start_investigation', 'cancel'],
            'investigation' => ['start_corrective_actions', 'cancel'],
            'corrective_actions' => ['close'],
            default => [],
        };

        return [
            'id' => $incident->id,
            'organization_id' => $incident->organization_id,
            'project_id' => $incident->project_id,
            'reported_by_user_id' => $incident->reported_by_user_id,
            'assigned_to_user_id' => $incident->assigned_to_user_id,
            'triaged_by_user_id' => $incident->triaged_by_user_id,
            'cancelled_by_user_id' => $incident->cancelled_by_user_id,
            'closed_by_user_id' => $incident->closed_by_user_id,
            'incident_number' => $incident->incident_number,
            'title' => $incident->title,
            'incident_type' => $incident->incident_type,
            'severity' => $incident->severity,
            'status' => $incident->status,
            'status_label' => trans_message("safety_management.incident_statuses.{$incident->status}"),
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
            'location_name' => $incident->location_name,
            'description' => $incident->description,
            'immediate_actions' => $incident->immediate_actions,
            'root_cause' => $incident->root_cause,
            'corrective_actions' => $incident->corrective_actions,
            'triage_comment' => $incident->triage_comment,
            'cancellation_reason' => $incident->cancellation_reason,
            'triaged_at' => $incident->triaged_at?->toIso8601String(),
            'investigation_started_at' => $incident->investigation_started_at?->toIso8601String(),
            'corrective_actions_started_at' => $incident->corrective_actions_started_at?->toIso8601String(),
            'cancelled_at' => $incident->cancelled_at?->toIso8601String(),
            'closed_at' => $incident->closed_at?->toIso8601String(),
            'workflow_summary' => [
                'stage' => $incident->status,
                'status' => $incident->status,
                'stage_label' => trans_message("safety_management.incident_statuses.{$incident->status}"),
                'next_action' => $actions[0] ?? null,
                'next_action_label' => $actions === [] ? null : trans_message("safety_management.actions.{$actions[0]}"),
                'available_actions' => $actions,
                'blockers' => $this->problemFlags($incident),
                'warnings' => [],
            ],
            'problem_flags' => $this->problemFlags($incident),
            'available_actions' => $actions,
            'project' => $this->whenLoaded('project', fn () => $incident->project ? [
                'id' => $incident->project->id,
                'name' => $incident->project->name,
            ] : null),
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $incident->assignedUser ? [
                'id' => $incident->assignedUser->id,
                'name' => $incident->assignedUser->name,
            ] : null),
            'metadata' => $incident->metadata,
            'created_at' => $incident->created_at?->toIso8601String(),
            'updated_at' => $incident->updated_at?->toIso8601String(),
        ];
    }

    private function problemFlags(SafetyIncident $incident): array
    {
        if (in_array($incident->status, ['reported', 'triage', 'investigation', 'corrective_actions'], true)) {
            return [[
                'code' => 'investigation_required',
                'severity' => $incident->severity === 'critical' ? 'critical' : 'warning',
                'message' => trans_message('safety_management.problem_flags.investigation_required'),
            ]];
        }

        return [];
    }
}
