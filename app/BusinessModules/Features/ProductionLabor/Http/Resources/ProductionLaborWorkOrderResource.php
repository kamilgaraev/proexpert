<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Http\Resources;

use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductionLaborWorkOrder */
final class ProductionLaborWorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductionLaborWorkOrder $workOrder */
        $workOrder = $this->resource;
        $actions = $this->actions($workOrder->status);

        return [
            'id' => $workOrder->id,
            'organization_id' => $workOrder->organization_id,
            'project_id' => $workOrder->project_id,
            'schedule_task_id' => $workOrder->schedule_task_id,
            'contractor_id' => $workOrder->contractor_id,
            'order_number' => $workOrder->order_number,
            'title' => $workOrder->title,
            'assignee_type' => $workOrder->assignee_type,
            'assignee_name' => $workOrder->assignee_name,
            'planned_start_date' => $workOrder->planned_start_date?->toDateString(),
            'planned_finish_date' => $workOrder->planned_finish_date?->toDateString(),
            'status' => $workOrder->status,
            'status_label' => trans_message("production_labor.work_order_statuses.{$workOrder->status}"),
            'return_reason' => $workOrder->return_reason,
            'workflow_summary' => [
                'stage' => $workOrder->status,
                'status' => $workOrder->status,
                'stage_label' => trans_message("production_labor.work_order_statuses.{$workOrder->status}"),
                'next_action' => $actions[0] ?? null,
                'next_action_label' => $actions === [] ? null : trans_message("production_labor.actions.{$actions[0]}"),
                'available_actions' => $actions,
                'blockers' => [],
                'warnings' => [],
            ],
            'problem_flags' => [],
            'available_actions' => $actions,
            'lines' => ProductionLaborWorkOrderLineResource::collection($this->whenLoaded('lines')),
            'linked_entities' => [
                'project_id' => $workOrder->project_id,
                'schedule_task_id' => $workOrder->schedule_task_id,
                'contractor_id' => $workOrder->contractor_id,
            ],
            'project' => $this->whenLoaded('project', fn () => $workOrder->project ? [
                'id' => $workOrder->project->id,
                'name' => $workOrder->project->name,
            ] : null),
            'issued_at' => $workOrder->issued_at?->toIso8601String(),
            'submitted_at' => $workOrder->submitted_at?->toIso8601String(),
            'accepted_at' => $workOrder->accepted_at?->toIso8601String(),
            'closed_at' => $workOrder->closed_at?->toIso8601String(),
            'metadata' => $workOrder->metadata,
            'created_at' => $workOrder->created_at?->toIso8601String(),
            'updated_at' => $workOrder->updated_at?->toIso8601String(),
        ];
    }

    private function actions(string $status): array
    {
        return match ($status) {
            'draft' => ['issue'],
            'issued' => ['start', 'submit', 'cancel'],
            'in_progress' => ['submit', 'cancel'],
            'submitted' => ['accept', 'return'],
            'accepted' => ['prepare_payroll', 'close'],
            default => [],
        };
    }
}
