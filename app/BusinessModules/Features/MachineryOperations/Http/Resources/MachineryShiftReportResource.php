<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Resources;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MachineryShiftReport */
final class MachineryShiftReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MachineryShiftReport $shift */
        $shift = $this->resource;
        $actions = match ($shift->status) {
            'draft' => ['submit'],
            'submitted' => ['approve', 'reject'],
            default => [],
        };

        return [
            'id' => $shift->id,
            'organization_id' => $shift->organization_id,
            'asset_id' => $shift->asset_id,
            'project_id' => $shift->project_id,
            'assignment_id' => $shift->assignment_id,
            'reported_by_user_id' => $shift->reported_by_user_id,
            'approved_by_user_id' => $shift->approved_by_user_id,
            'report_date' => $shift->report_date?->toDateString(),
            'status' => $shift->status,
            'status_label' => trans_message("machinery_operations.shift_statuses.{$shift->status}"),
            'planned_hours' => $shift->planned_hours,
            'actual_hours' => $shift->actual_hours,
            'fuel_consumed' => $shift->fuel_consumed,
            'meter_start' => $shift->meter_start,
            'meter_end' => $shift->meter_end,
            'work_description' => $shift->work_description,
            'rejection_reason' => $shift->rejection_reason,
            'submitted_at' => $shift->submitted_at?->toIso8601String(),
            'approved_at' => $shift->approved_at?->toIso8601String(),
            'rejected_at' => $shift->rejected_at?->toIso8601String(),
            'workflow_summary' => [
                'stage' => $shift->status,
                'status' => $shift->status,
                'stage_label' => trans_message("machinery_operations.shift_statuses.{$shift->status}"),
                'next_action' => $actions[0] ?? null,
                'next_action_label' => $actions === [] ? null : trans_message("machinery_operations.actions.{$actions[0]}"),
                'available_actions' => $actions,
                'blockers' => [],
                'warnings' => [],
            ],
            'problem_flags' => [],
            'available_actions' => $actions,
            'linked_entities' => [
                'asset_id' => $shift->asset_id,
                'project_id' => $shift->project_id,
                'assignment_id' => $shift->assignment_id,
            ],
            'asset' => $this->whenLoaded('asset', fn () => $shift->asset ? [
                'id' => $shift->asset->id,
                'name' => $shift->asset->name,
                'asset_code' => $shift->asset->asset_code,
                'status' => $shift->asset->status,
            ] : null),
            'project' => $this->whenLoaded('project', fn () => $shift->project ? [
                'id' => $shift->project->id,
                'name' => $shift->project->name,
            ] : null),
            'created_at' => $shift->created_at?->toIso8601String(),
            'updated_at' => $shift->updated_at?->toIso8601String(),
        ];
    }
}
