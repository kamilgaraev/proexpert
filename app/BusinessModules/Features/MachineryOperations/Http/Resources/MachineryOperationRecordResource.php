<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Resources;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDefect;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryProductionRecord;
use App\BusinessModules\Features\MachineryOperations\Support\MachineryStatusLabel;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MachineryOperationRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return match (true) {
            $this->resource instanceof MachineryAssignment => $this->assignment($this->resource),
            $this->resource instanceof MachineryDowntime => $this->downtime($this->resource),
            $this->resource instanceof MachineryFuelIssue => $this->fuelIssue($this->resource),
            $this->resource instanceof MachineryProductionRecord => $this->productionRecord($this->resource),
            $this->resource instanceof MachineryMaintenanceOrder => $this->maintenanceOrder($request, $this->resource),
            $this->resource instanceof MachineryDefect => $this->defect($this->resource),
            default => [],
        };
    }

    private function assignment(MachineryAssignment $assignment): array
    {
        $assetRequest = $assignment->relationLoaded('assetRequest') ? $assignment->assetRequest : null;

        return [
            'id' => $assignment->id,
            'asset_request_id' => $assignment->asset_request_id,
            'site_request_id' => $assetRequest?->site_request_id,
            'origin_type' => $assetRequest?->origin_type,
            'request_number' => match ($assetRequest?->origin_type) {
                'site_request' => $assetRequest->site_request_id === null ? null : (string) $assetRequest->site_request_id,
                'manual' => (string) $assetRequest->id,
                default => null,
            },
            'asset_id' => $assignment->asset_id,
            'organization_asset_id' => $assignment->organization_asset_id,
            'project_id' => $assignment->project_id,
            'schedule_task_id' => $assignment->schedule_task_id,
            'requested_by_user_id' => $assignment->requested_by_user_id,
            'approved_by_user_id' => $assignment->approved_by_user_id,
            'status' => $assignment->status,
            'status_label' => MachineryStatusLabel::for('assignment_statuses', $assignment->status),
            'planned_start_at' => $assignment->planned_start_at?->toIso8601String(),
            'planned_end_at' => $assignment->planned_end_at?->toIso8601String(),
            'actual_start_at' => $assignment->actual_start_at?->toIso8601String(),
            'actual_end_at' => $assignment->actual_end_at?->toIso8601String(),
            'planned_hours' => $assignment->planned_hours,
            'comment' => $assignment->comment,
            'project' => $assignment->relationLoaded('project') ? $assignment->project : null,
            'linked_entities' => [
                'asset_request_id' => $assignment->asset_request_id,
                'asset_id' => $assignment->asset_id,
                'organization_asset_id' => $assignment->organization_asset_id,
                'project_id' => $assignment->project_id,
                'schedule_task_id' => $assignment->schedule_task_id,
            ],
        ];
    }

    private function downtime(MachineryDowntime $downtime): array
    {
        return [
            'id' => $downtime->id,
            'asset_id' => $downtime->asset_id,
            'organization_asset_id' => $downtime->organization_asset_id,
            'project_id' => $downtime->project_id,
            'shift_report_id' => $downtime->shift_report_id,
            'reason' => $downtime->reason,
            'reason_code' => $downtime->reason_code ?? $downtime->reason,
            'reason_label' => trans_message('machinery_operations.downtime_reasons.'.($downtime->reason_code ?? $downtime->reason)),
            'reason_original' => $downtime->reason_original,
            'started_at' => $downtime->started_at?->toIso8601String(),
            'ended_at' => $downtime->ended_at?->toIso8601String(),
            'duration_minutes' => $downtime->duration_minutes,
            'comment' => $downtime->comment,
        ];
    }

    private function fuelIssue(MachineryFuelIssue $fuelIssue): array
    {
        return [
            'id' => $fuelIssue->id,
            'asset_id' => $fuelIssue->asset_id,
            'organization_asset_id' => $fuelIssue->organization_asset_id,
            'project_id' => $fuelIssue->project_id,
            'issued_by_user_id' => $fuelIssue->issued_by_user_id,
            'issued_at' => $fuelIssue->issued_at?->toIso8601String(),
            'fuel_type' => $fuelIssue->fuel_type,
            'fuel_type_code' => $fuelIssue->fuel_type_code ?? $fuelIssue->fuel_type,
            'fuel_type_label' => trans_message('machinery_operations.fuel_types.'.($fuelIssue->fuel_type_code ?? $fuelIssue->fuel_type)),
            'quantity' => $fuelIssue->quantity,
            'unit' => $fuelIssue->unit,
            'unit_code' => $fuelIssue->unit_code ?? $fuelIssue->unit,
            'unit_label' => trans_message('machinery_operations.fuel_units.'.($fuelIssue->unit_code ?? $fuelIssue->unit)),
            'cost' => $fuelIssue->cost,
            'comment' => $fuelIssue->comment,
        ];
    }

    private function productionRecord(MachineryProductionRecord $record): array
    {
        return [
            'id' => $record->id,
            'asset_id' => $record->asset_id,
            'organization_asset_id' => $record->organization_asset_id,
            'project_id' => $record->project_id,
            'shift_report_id' => $record->shift_report_id,
            'recorded_by_user_id' => $record->recorded_by_user_id,
            'recorded_at' => $record->recorded_at?->toIso8601String(),
            'quantity' => $record->quantity,
            'unit' => $record->unit,
            'comment' => $record->comment,
        ];
    }

    private function maintenanceOrder(Request $request, MachineryMaintenanceOrder $order): array
    {
        $candidateActions = match ($order->status) {
            'open', 'in_progress' => ['complete'],
            default => [],
        };
        $actor = $request->user();
        $actions = $actor instanceof User && app(AuthorizationService::class)->can(
            $actor,
            'machinery-operations.downtime.manage',
            ['organization_id' => (int) $order->organization_id],
        ) ? $candidateActions : [];

        return [
            'id' => $order->id,
            'asset_id' => $order->asset_id,
            'organization_asset_id' => $order->organization_asset_id,
            'project_id' => $order->project_id,
            'requested_by_user_id' => $order->requested_by_user_id,
            'completed_by_user_id' => $order->completed_by_user_id,
            'order_number' => $order->order_number,
            'title' => $order->title,
            'maintenance_type' => $order->maintenance_type,
            'priority' => $order->priority,
            'status' => $order->status,
            'status_label' => MachineryStatusLabel::for('maintenance_statuses', $order->status),
            'description' => $order->description,
            'planned_at' => $order->planned_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cost' => $order->cost,
            'completion_comment' => $order->completion_comment,
            'inspection' => $order->relationLoaded('inspection') && $order->inspection ? [
                'id' => $order->inspection->id,
                'result' => $order->inspection->result,
                'notes' => $order->inspection->notes,
                'inspected_at' => $order->inspection->inspected_at?->toIso8601String(),
            ] : null,
            'workflow_summary' => [
                'stage' => $order->status,
                'status' => $order->status,
                'stage_label' => MachineryStatusLabel::for('maintenance_statuses', $order->status),
                'next_action' => $actions[0] ?? null,
                'next_action_label' => $actions === [] ? null : trans_message("machinery_operations.actions.{$actions[0]}"),
                'available_actions' => $actions,
                'blockers' => [],
                'warnings' => [],
            ],
            'problem_flags' => [],
            'available_actions' => $actions,
            'linked_entities' => [
                'asset_id' => $order->asset_id,
                'organization_asset_id' => $order->organization_asset_id,
                'project_id' => $order->project_id,
            ],
        ];
    }

    private function defect(MachineryDefect $defect): array
    {
        return [
            'id' => $defect->id,
            'asset_id' => $defect->asset_id,
            'organization_asset_id' => $defect->organization_asset_id,
            'project_id' => $defect->project_id,
            'defect_code' => $defect->defect_code,
            'severity' => $defect->severity,
            'status' => $defect->status,
            'description' => $defect->description,
            'reported_at' => $defect->reported_at?->toIso8601String(),
            'resolved_at' => $defect->resolved_at?->toIso8601String(),
            'available_actions' => [],
        ];
    }
}
