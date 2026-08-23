<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Resources;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\MachineryOperations\Support\MachineryStatusLabel;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MachineryShiftReport */
final class MachineryShiftReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MachineryShiftReport $shift */
        $shift = $this->resource;
        $candidateActions = match ($shift->status) {
            'draft', 'blocked' => ['cancel'],
            'completed' => ['submit', 'cancel'],
            'submitted' => ['approve', 'reject', 'cancel'],
            'approved', 'rejected' => ['cancel'],
            default => [],
        };
        $actor = $request->user();
        $actions = $actor instanceof User
            ? array_values(array_filter($candidateActions, function (string $action) use ($actor, $shift): bool {
                $permission = $action === 'submit'
                    ? 'machinery-operations.shifts.create'
                    : 'machinery-operations.shifts.approve';

                return app(AuthorizationService::class)->can($actor, $permission, [
                    'organization_id' => (int) $shift->organization_id,
                ]);
            }))
            : [];

        return [
            'id' => $shift->id,
            'organization_id' => $shift->organization_id,
            'asset_id' => $shift->asset_id,
            'organization_asset_id' => $shift->organization_asset_id,
            'project_id' => $shift->project_id,
            'assignment_id' => $shift->assignment_id,
            'schedule_task_id' => $shift->schedule_task_id,
            'construction_journal_entry_id' => $shift->construction_journal_entry_id,
            'reported_by_user_id' => $shift->reported_by_user_id,
            'finished_by_user_id' => $shift->finished_by_user_id,
            'cancelled_by_user_id' => $shift->cancelled_by_user_id,
            'approved_by_user_id' => $shift->approved_by_user_id,
            'report_date' => $shift->report_date?->toDateString(),
            'status' => $shift->status,
            'status_label' => MachineryStatusLabel::for('shift_statuses', $shift->status),
            'planned_hours' => $shift->planned_hours,
            'actual_hours' => $shift->actual_hours,
            'hourly_rate_snapshot' => $shift->hourly_rate_snapshot,
            'cost_evidence' => $shift->cost_evidence,
            'fuel_consumed' => $shift->fuel_consumed,
            'meter_start' => $shift->meter_start,
            'meter_end' => $shift->meter_end,
            'work_description' => $shift->work_description,
            'finish_evidence' => $shift->finish_evidence,
            'rejection_reason' => $shift->rejection_reason,
            'cancellation_reason' => $shift->cancellation_reason,
            'submitted_at' => $shift->submitted_at?->toIso8601String(),
            'approved_at' => $shift->approved_at?->toIso8601String(),
            'rejected_at' => $shift->rejected_at?->toIso8601String(),
            'started_at' => $shift->started_at?->toIso8601String(),
            'finished_at' => $shift->finished_at?->toIso8601String(),
            'cancelled_at' => $shift->cancelled_at?->toIso8601String(),
            'workflow_summary' => [
                'stage' => $shift->status,
                'status' => $shift->status,
                'stage_label' => MachineryStatusLabel::for('shift_statuses', $shift->status),
                'next_action' => $actions[0] ?? null,
                'next_action_label' => $actions === [] ? null : trans_message("machinery_operations.actions.{$actions[0]}"),
                'available_actions' => $actions,
                'blockers' => [],
                'warnings' => [],
            ],
            'problem_flags' => $shift->status === 'blocked' ? [[
                'code' => 'pre_shift_inspection_blocked',
                'severity' => 'critical',
                'message' => trans_message('machinery_operations.errors.pre_shift_inspection_blocked'),
            ]] : [],
            'available_actions' => $actions,
            'inspections' => $this->whenLoaded('inspections', fn () => $shift->inspections->map(static fn ($inspection): array => [
                'id' => $inspection->id,
                'inspection_type' => $inspection->inspection_type,
                'result' => $inspection->result,
                'notes' => $inspection->notes,
                'evidence' => $inspection->evidence,
                'defects' => $inspection->defects,
                'inspected_by_user_id' => $inspection->inspected_by_user_id,
                'inspected_at' => $inspection->inspected_at?->toIso8601String(),
            ])->values()),
            'linked_entities' => [
                'asset_id' => $shift->asset_id,
                'organization_asset_id' => $shift->organization_asset_id,
                'project_id' => $shift->project_id,
                'assignment_id' => $shift->assignment_id,
                'schedule_task_id' => $shift->schedule_task_id,
                'construction_journal_entry_id' => $shift->construction_journal_entry_id,
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
