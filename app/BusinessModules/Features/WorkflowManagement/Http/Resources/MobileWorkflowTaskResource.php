<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkflowManagement\Http\Resources;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompletedWork */
final class MobileWorkflowTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CompletedWork $task */
        $task = $this->resource;
        $workflow = $this->workflowData($task);
        $availableActions = $this->availableActions($request, $task);
        $nextAction = $availableActions[0] ?? null;

        return [
            'id' => $task->id,
            'organization_id' => $task->organization_id,
            'project_id' => $task->project_id,
            'project_label' => $task->project?->name,
            'work_type_id' => $task->work_type_id,
            'work_type_label' => $task->workType?->name,
            'contract_id' => $task->contract_id,
            'contract_label' => $task->contract?->number,
            'contractor_id' => $task->contractor_id,
            'contractor_label' => $task->contractor?->name ?? $task->contract?->contractor?->name,
            'assigned_user_id' => $task->user_id,
            'assigned_user_label' => $task->user?->name,
            'schedule_task_id' => $task->schedule_task_id,
            'schedule_task_label' => $task->scheduleTask?->name,
            'schedule_label' => $task->scheduleTask?->schedule?->name,
            'estimate_item_id' => $task->estimate_item_id,
            'estimate_item_label' => $task->estimateItem?->name,
            'work_origin_type' => $task->work_origin_type,
            'work_origin_label' => $task->work_origin_type
                ? $this->label("workflow_management.origins.{$task->work_origin_type}")
                : null,
            'planning_status' => $task->planning_status,
            'planning_status_label' => $task->planning_status
                ? $this->label("workflow_management.planning_statuses.{$task->planning_status}")
                : null,
            'quantity' => $task->quantity !== null ? (float) $task->quantity : null,
            'completed_quantity' => $task->completed_quantity !== null ? (float) $task->completed_quantity : null,
            'measurement_unit_label' => $task->workType?->measurementUnit?->short_name
                ?? $task->scheduleTask?->measurementUnit?->short_name
                ?? $task->estimateItem?->measurementUnit?->short_name,
            'price' => $task->price !== null ? (float) $task->price : null,
            'total_amount' => $task->total_amount !== null ? (float) $task->total_amount : null,
            'completion_date' => $task->completion_date?->toDateString(),
            'notes' => $task->notes,
            'status' => $task->status,
            'status_label' => $this->label("workflow_management.statuses.{$task->status}"),
            'comments' => $workflow['comments'],
            'status_history' => $workflow['status_history'],
            'available_actions' => $availableActions,
            'workflow_summary' => [
                'stage' => $task->status,
                'status' => $task->status,
                'stage_label' => $this->label("workflow_management.statuses.{$task->status}"),
                'next_action' => $nextAction,
                'next_action_label' => $nextAction ? $this->label("workflow_management.actions.{$nextAction}") : null,
                'available_actions' => $availableActions,
                'blockers' => [],
                'warnings' => [],
            ],
            'problem_flags' => [],
            'linked_entities' => [
                'project_id' => $task->project_id,
                'contract_id' => $task->contract_id,
                'schedule_task_id' => $task->schedule_task_id,
                'estimate_item_id' => $task->estimate_item_id,
            ],
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    private function availableActions(Request $request, CompletedWork $task): array
    {
        $user = $request->user();
        if (!$user) {
            return [];
        }

        $canEdit = app(AuthorizationService::class)->can(
            $user,
            'completed_works.edit',
            ['organization_id' => (int) $task->organization_id]
        );

        if (!$canEdit) {
            return [];
        }

        $actions = match ($task->status) {
            'draft', 'pending' => ['approve', 'reject', 'request_changes'],
            'in_review' => ['approve', 'reject'],
            default => [],
        };

        if (!in_array($task->status, ['cancelled'], true)) {
            $actions[] = 'comment';
        }

        return $actions;
    }

    private function workflowData(CompletedWork $task): array
    {
        $additionalInfo = is_array($task->additional_info) ? $task->additional_info : [];
        $workflow = is_array($additionalInfo['mobile_workflow'] ?? null)
            ? $additionalInfo['mobile_workflow']
            : [];

        return [
            'status_history' => array_values(is_array($workflow['status_history'] ?? null) ? $workflow['status_history'] : []),
            'comments' => array_values(is_array($workflow['comments'] ?? null) ? $workflow['comments'] : []),
        ];
    }

    private function label(string $key): string
    {
        return trans_message($key);
    }
}
