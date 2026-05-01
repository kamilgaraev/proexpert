<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupplierProposalDecision */
class SupplierProposalDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_request_id' => $this->supplier_request_id,
            'winning_supplier_proposal_id' => $this->winning_supplier_proposal_id,
            'cheapest_supplier_proposal_id' => $this->cheapest_supplier_proposal_id,
            'status' => $this->status->value,
            'is_lowest_price_selected' => $this->is_lowest_price_selected,
            'decision_reason' => $this->decision_reason,
            'comparison_snapshot' => $this->comparison_snapshot,
            'selected_by' => $this->selected_by,
            'selected_at' => $this->selected_at?->toIso8601String(),
            'winning_proposal' => $this->whenLoaded(
                'winningProposal',
                fn () => $this->winningProposal ? new SupplierProposalResource($this->winningProposal) : null
            ),
            'cheapest_proposal' => $this->whenLoaded(
                'cheapestProposal',
                fn () => $this->cheapestProposal ? new SupplierProposalResource($this->cheapestProposal) : null
            ),
            'approvals' => $this->whenLoaded(
                'approvals',
                fn () => ProcurementApprovalResource::collection($this->approvals)
            ),
            'audit_events' => $this->whenLoaded(
                'auditEvents',
                fn () => ProcurementAuditEventResource::collection($this->auditEvents)
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
