<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Resources;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryProductionRecord;
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
            $this->resource instanceof MachineryMaintenanceOrder => $this->maintenanceOrder($this->resource),
            default => [],
        };
    }

    private function assignment(MachineryAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'asset_id' => $assignment->asset_id,
            'project_id' => $assignment->project_id,
            'schedule_task_id' => $assignment->schedule_task_id,
            'requested_by_user_id' => $assignment->requested_by_user_id,
            'approved_by_user_id' => $assignment->approved_by_user_id,
            'status' => $assignment->status,
            'planned_start_at' => $assignment->planned_start_at?->toIso8601String(),
            'planned_end_at' => $assignment->planned_end_at?->toIso8601String(),
            'actual_start_at' => $assignment->actual_start_at?->toIso8601String(),
            'actual_end_at' => $assignment->actual_end_at?->toIso8601String(),
            'planned_hours' => $assignment->planned_hours,
            'comment' => $assignment->comment,
            'linked_entities' => [
                'asset_id' => $assignment->asset_id,
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
            'project_id' => $downtime->project_id,
            'shift_report_id' => $downtime->shift_report_id,
            'reason' => $downtime->reason,
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
            'project_id' => $fuelIssue->project_id,
            'issued_by_user_id' => $fuelIssue->issued_by_user_id,
            'issued_at' => $fuelIssue->issued_at?->toIso8601String(),
            'fuel_type' => $fuelIssue->fuel_type,
            'quantity' => $fuelIssue->quantity,
            'unit' => $fuelIssue->unit,
            'cost' => $fuelIssue->cost,
            'comment' => $fuelIssue->comment,
        ];
    }

    private function productionRecord(MachineryProductionRecord $record): array
    {
        return [
            'id' => $record->id,
            'asset_id' => $record->asset_id,
            'project_id' => $record->project_id,
            'shift_report_id' => $record->shift_report_id,
            'recorded_by_user_id' => $record->recorded_by_user_id,
            'recorded_at' => $record->recorded_at?->toIso8601String(),
            'quantity' => $record->quantity,
            'unit' => $record->unit,
            'comment' => $record->comment,
        ];
    }

    private function maintenanceOrder(MachineryMaintenanceOrder $order): array
    {
        $actions = match ($order->status) {
            'open', 'in_progress' => ['complete'],
            default => [],
        };

        return [
            'id' => $order->id,
            'asset_id' => $order->asset_id,
            'project_id' => $order->project_id,
            'requested_by_user_id' => $order->requested_by_user_id,
            'completed_by_user_id' => $order->completed_by_user_id,
            'order_number' => $order->order_number,
            'title' => $order->title,
            'maintenance_type' => $order->maintenance_type,
            'priority' => $order->priority,
            'status' => $order->status,
            'status_label' => trans_message("machinery_operations.maintenance_statuses.{$order->status}"),
            'description' => $order->description,
            'planned_at' => $order->planned_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cost' => $order->cost,
            'completion_comment' => $order->completion_comment,
            'workflow_summary' => [
                'stage' => $order->status,
                'status' => $order->status,
                'stage_label' => trans_message("machinery_operations.maintenance_statuses.{$order->status}"),
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
                'project_id' => $order->project_id,
            ],
        ];
    }
}
