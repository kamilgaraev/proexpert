<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Enums\ProcurementApprovalStatusEnum;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MobileProcurementApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProcurementApproval $approval */
        $approval = $this->resource;

        return [
            'id' => $approval->id,
            'organization_id' => $approval->organization_id,
            'reason_code' => $approval->reason_code,
            'reason_label' => $this->reasonLabel($approval->reason_code),
            'status' => $this->statusValue($approval),
            'status_label' => trans_message('procurement.mobile.approval_statuses.' . $this->statusValue($approval)),
            'requested_by_label' => $approval->requestedBy?->name,
            'approved_by_label' => $approval->approvedBy?->name,
            'rejected_by_label' => $approval->rejectedBy?->name,
            'requested_at' => $approval->requested_at?->toIso8601String(),
            'resolved_at' => $approval->resolved_at?->toIso8601String(),
            'comment' => $approval->comment,
            'decision_summary' => $this->decisionSummary($approval),
            'context_summary' => $this->contextSummary($approval),
            'can_resolve' => (bool) $approval->getAttribute('can_resolve'),
            'resolution_blockers' => $approval->getAttribute('resolution_blockers') ?? [],
            'available_actions' => $this->availableActions($approval),
            'created_at' => $approval->created_at?->toIso8601String(),
            'updated_at' => $approval->updated_at?->toIso8601String(),
        ];
    }

    private function statusValue(ProcurementApproval $approval): string
    {
        $status = $approval->status;

        return $status instanceof ProcurementApprovalStatusEnum ? $status->value : (string) $status;
    }

    private function reasonLabel(?string $reasonCode): ?string
    {
        return match ($reasonCode) {
            'budget_exceeded' => trans_message('procurement.mobile.approval_reasons.budget_exceeded'),
            'non_lowest_price' => trans_message('procurement.mobile.approval_reasons.non_lowest_price'),
            'external_supplier_missing_identity' => trans_message('procurement.mobile.approval_reasons.external_supplier_missing_identity'),
            'order_changed_after_acceptance' => trans_message('procurement.mobile.approval_reasons.order_changed_after_acceptance'),
            default => null,
        };
    }

    private function decisionSummary(ProcurementApproval $approval): ?array
    {
        $decision = $approval->approvable;
        if (!$decision instanceof SupplierProposalDecision) {
            return null;
        }

        $proposal = $decision->winningProposal;
        $snapshot = is_array($proposal?->supplier_snapshot) ? $proposal->supplier_snapshot : [];

        return [
            'id' => $decision->id,
            'status' => $decision->status instanceof \BackedEnum ? $decision->status->value : (string) $decision->status,
            'supplier_label' => $snapshot['display_name'] ?? null,
            'proposal_id' => $proposal?->id,
            'proposal_number' => $proposal?->proposal_number,
            'total_amount' => $proposal !== null ? (float) $proposal->total_amount : null,
            'currency' => $proposal?->currency,
            'is_lowest_price_selected' => (bool) $decision->is_lowest_price_selected,
        ];
    }

    private function contextSummary(ProcurementApproval $approval): array
    {
        $context = is_array($approval->context) ? $approval->context : [];

        return [
            'selected_total' => $this->numeric($context['selected_total'] ?? null),
            'cheapest_total' => $this->numeric($context['cheapest_total'] ?? null),
            'budget_amount' => $this->numeric($context['budget_amount'] ?? null),
            'delta_amount' => $this->numeric($context['delta_amount'] ?? null),
            'delta_percent' => $this->numeric($context['delta_percent'] ?? null),
            'currency' => isset($context['currency']) ? (string) $context['currency'] : null,
            'supplier_label' => isset($context['supplier_name']) ? (string) $context['supplier_name'] : null,
        ];
    }

    private function availableActions(ProcurementApproval $approval): array
    {
        return $this->statusValue($approval) === ProcurementApprovalStatusEnum::PENDING->value
            && (bool) $approval->getAttribute('can_resolve')
            ? ['approve', 'reject']
            : [];
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
