<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Resources;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeImpact;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChangeRequest */
final class ChangeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ChangeRequest $change */
        $change = $this->resource;
        $impact = $change->relationLoaded('impact') ? $change->impact : null;
        $approvals = $change->relationLoaded('approvals') ? $change->approvals : collect();
        $customerApproval = $approvals->where('approval_type', 'customer')->sortByDesc('id')->first();

        return [
            'id' => $change->id,
            'organization_id' => $change->organization_id,
            'project_id' => $change->project_id,
            'related_rfi_id' => $change->related_rfi_id,
            'change_number' => $change->change_number,
            'title' => $change->title,
            'reason' => $change->reason,
            'description' => $change->description,
            'initiator_type' => $change->initiator_type,
            'status' => $change->status,
            'affected_schedule_task_ids' => $change->affected_schedule_task_ids ?? [],
            'affected_estimate_item_ids' => $change->affected_estimate_item_ids ?? [],
            'linked_entities' => $change->linked_entities ?? [],
            'implementation_comment' => $change->implementation_comment,
            'impact' => $impact ? $this->impactPayload($impact) : null,
            'customer_approval' => $customerApproval ? [
                'id' => $customerApproval->id,
                'status' => $customerApproval->status,
                'comment' => $customerApproval->comment,
                'decided_at' => $customerApproval->decided_at?->toIso8601String(),
            ] : null,
            'variation_orders' => VariationOrderResource::collection($this->whenLoaded('variationOrders'))->resolve(),
            'workflow_summary' => [
                'status' => $change->status,
                'available_actions' => $this->availableActions($change->status, (bool) ($impact?->requires_customer_approval ?? false)),
            ],
            'problem_flags' => $this->problemFlags($impact),
            'created_at' => $change->created_at?->toIso8601String(),
            'updated_at' => $change->updated_at?->toIso8601String(),
        ];
    }

    private function impactPayload(ChangeImpact $impact): array
    {
        return [
            'id' => $impact->id,
            'cost_delta' => $impact->cost_delta,
            'schedule_delta_days' => $impact->schedule_delta_days,
            'requires_contract_change' => $impact->requires_contract_change,
            'requires_estimate_revision' => $impact->requires_estimate_revision,
            'requires_procurement_update' => $impact->requires_procurement_update,
            'requires_customer_approval' => $impact->requires_customer_approval,
            'affected_schedule_task_ids' => $impact->affected_schedule_task_ids ?? [],
            'affected_estimate_item_ids' => $impact->affected_estimate_item_ids ?? [],
            'affected_contract_ids' => $impact->affected_contract_ids ?? [],
            'summary' => $impact->summary,
        ];
    }

    private function problemFlags(?ChangeImpact $impact): array
    {
        $flags = [];

        if ($impact && (int) $impact->schedule_delta_days !== 0) {
            $flags[] = [
                'code' => 'schedule_impact',
                'severity' => abs((int) $impact->schedule_delta_days) > 3 ? 'warning' : 'info',
                'message' => trans_message('change_management.flags.schedule_impact'),
                'schedule_delta_days' => (int) $impact->schedule_delta_days,
            ];
        }

        if ($impact && (float) $impact->cost_delta !== 0.0) {
            $flags[] = [
                'code' => 'cost_impact',
                'severity' => 'info',
                'message' => trans_message('change_management.flags.cost_impact'),
                'cost_delta' => $impact->cost_delta,
            ];
        }

        return $flags;
    }

    private function availableActions(string $status, bool $requiresCustomerApproval): array
    {
        return match ($status) {
            'draft' => ['submit'],
            'submitted' => ['assess_impact', 'reject'],
            'impact_assessment' => ['internal_review', 'reject'],
            'internal_review' => $requiresCustomerApproval ? ['customer_review', 'reject'] : ['approve', 'reject'],
            'customer_review' => ['customer_approve', 'reject'],
            'approved' => ['create_variation_order', 'implement', 'cancel'],
            'implemented' => ['close'],
            default => [],
        };
    }
}
