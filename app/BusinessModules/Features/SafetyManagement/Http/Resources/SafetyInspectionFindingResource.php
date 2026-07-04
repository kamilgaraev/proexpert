<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionFinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SafetyInspectionFindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $finding = $this->resource;

        if (!$finding instanceof SafetyInspectionFinding) {
            return [];
        }

        return [
            'id' => $finding->id,
            'organization_id' => $finding->organization_id,
            'project_id' => $finding->project_id,
            'inspection_id' => $finding->inspection_id,
            'inspection_item_id' => $finding->inspection_item_id,
            'assigned_to_user_id' => $finding->assigned_to_user_id,
            'created_by_user_id' => $finding->created_by_user_id,
            'resolved_by_user_id' => $finding->resolved_by_user_id,
            'corrective_action_id' => $finding->corrective_action_id,
            'finding_number' => $finding->finding_number,
            'title' => $finding->title,
            'description' => $finding->description,
            'severity' => $finding->severity,
            'status' => $finding->status,
            'status_label' => trans_message("safety_management.finding_statuses.{$finding->status}"),
            'due_date' => $finding->due_date?->toDateString(),
            'resolution_comment' => $finding->resolution_comment,
            'resolved_at' => $finding->resolved_at?->toIso8601String(),
            'evidence_files' => $finding->evidence_files ?? [],
            'problem_flags' => $this->problemFlags($finding),
            'project' => $this->whenLoaded('project', fn () => $finding->project ? [
                'id' => $finding->project->id,
                'name' => $finding->project->name,
            ] : null),
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $finding->assignedUser ? [
                'id' => $finding->assignedUser->id,
                'name' => $finding->assignedUser->name,
            ] : null),
            'metadata' => $finding->metadata,
            'created_at' => $finding->created_at?->toIso8601String(),
            'updated_at' => $finding->updated_at?->toIso8601String(),
        ];
    }

    private function problemFlags(SafetyInspectionFinding $finding): array
    {
        if ($finding->status === 'open' && $finding->due_date !== null && $finding->due_date->isPast()) {
            return [[
                'code' => 'inspection_finding_overdue',
                'severity' => 'critical',
                'message' => trans_message('safety_management.problem_flags.inspection_finding_overdue'),
            ]];
        }

        return [];
    }
}
