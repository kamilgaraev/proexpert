<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SafetyInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $inspection = $this->resource;

        if (!$inspection instanceof SafetyInspection) {
            return [];
        }

        return [
            'id' => $inspection->id,
            'organization_id' => $inspection->organization_id,
            'project_id' => $inspection->project_id,
            'template_id' => $inspection->template_id,
            'permit_id' => $inspection->permit_id,
            'conducted_by_user_id' => $inspection->conducted_by_user_id,
            'inspection_number' => $inspection->inspection_number,
            'title' => $inspection->title,
            'inspection_type' => $inspection->inspection_type,
            'location_name' => $inspection->location_name,
            'risk_level' => $inspection->risk_level,
            'status' => $inspection->status,
            'status_label' => trans_message("safety_management.inspection_statuses.{$inspection->status}"),
            'planned_at' => $inspection->planned_at?->toIso8601String(),
            'conducted_at' => $inspection->conducted_at?->toIso8601String(),
            'result' => $inspection->result,
            'result_label' => $inspection->result === null ? null : trans_message("safety_management.inspection_results.{$inspection->result}"),
            'summary' => $inspection->summary,
            'workflow_summary' => $this->workflowSummary($inspection),
            'items' => $this->whenLoaded('items', fn () => $inspection->items->map(static fn ($item): array => [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'title' => $item->title,
                'requirement_text' => $item->requirement_text,
                'severity' => $item->severity,
                'status' => $item->status,
                'comment' => $item->comment,
                'evidence_files' => $item->evidence_files ?? [],
            ])->values()->all()),
            'findings' => $this->whenLoaded(
                'findings',
                fn () => SafetyInspectionFindingResource::collection($inspection->findings)->resolve()
            ),
            'project' => $this->whenLoaded('project', fn () => $inspection->project ? [
                'id' => $inspection->project->id,
                'name' => $inspection->project->name,
            ] : null),
            'metadata' => $inspection->metadata,
            'created_at' => $inspection->created_at?->toIso8601String(),
            'updated_at' => $inspection->updated_at?->toIso8601String(),
        ];
    }

    private function workflowSummary(SafetyInspection $inspection): array
    {
        $actions = match ($inspection->status) {
            'planned' => ['start', 'complete'],
            'in_progress' => ['complete'],
            default => [],
        };

        return [
            'stage' => $inspection->status,
            'status' => $inspection->status,
            'stage_label' => trans_message("safety_management.inspection_statuses.{$inspection->status}"),
            'next_action' => $actions[0] ?? null,
            'next_action_label' => $actions === [] ? null : trans_message("safety_management.actions.{$actions[0]}"),
            'available_actions' => $actions,
            'blockers' => [],
            'warnings' => (int) ($inspection->findings_count ?? 0) > 0 ? [[
                'code' => 'inspection_has_findings',
                'severity' => 'warning',
                'message' => trans_message('safety_management.problem_flags.inspection_has_findings'),
            ]] : [],
        ];
    }
}
