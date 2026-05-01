<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementApprovalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierPartyTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use Illuminate\Support\Facades\DB;

use function trans_message;

class ProcurementApprovalService
{
    public const REASON_BUDGET_EXCEEDED = 'budget_exceeded';
    public const REASON_NON_LOWEST_PRICE = 'non_lowest_price';
    public const REASON_EXTERNAL_SUPPLIER_MISSING_IDENTITY = 'external_supplier_missing_identity';
    public const REASON_ORDER_CHANGED_AFTER_ACCEPTANCE = 'order_changed_after_acceptance';

    public function evaluateForDecision(
        SupplierProposalDecision $decision,
        SupplierProposal $selectedProposal,
        array $comparison
    ): array {
        $risks = [];
        $selectedTotal = $this->selectedComparisonTotal($selectedProposal, $comparison);
        $supplierRequest = $decision->supplierRequest()
            ->with('purchaseRequest')
            ->first();
        $budgetAmount = $supplierRequest?->purchaseRequest?->budget_amount;

        if ($budgetAmount !== null && $selectedTotal > (float) $budgetAmount) {
            $risks[] = [
                'reason_code' => self::REASON_BUDGET_EXCEEDED,
                'context' => [
                    'selected_supplier_proposal_id' => $selectedProposal->id,
                    'selected_total' => $selectedTotal,
                    'budget_amount' => (float) $budgetAmount,
                    'currency' => $selectedProposal->currency,
                ],
            ];
        }

        if (!$decision->is_lowest_price_selected) {
            $risks[] = [
                'reason_code' => self::REASON_NON_LOWEST_PRICE,
                'context' => [
                    'selected_supplier_proposal_id' => $selectedProposal->id,
                    'cheapest_supplier_proposal_id' => $comparison['cheapest_supplier_proposal_id'] ?? null,
                    'selected_total' => $selectedTotal,
                    'currency' => $selectedProposal->currency,
                ],
            ];
        }

        $snapshot = is_array($selectedProposal->supplier_snapshot) ? $selectedProposal->supplier_snapshot : [];
        if (
            ($snapshot['type'] ?? null) === SupplierPartyTypeEnum::EXTERNAL->value
            && $this->blank($snapshot['tax_id'] ?? null)
        ) {
            $risks[] = [
                'reason_code' => self::REASON_EXTERNAL_SUPPLIER_MISSING_IDENTITY,
                'context' => [
                    'selected_supplier_proposal_id' => $selectedProposal->id,
                    'supplier_party_id' => $selectedProposal->supplier_party_id,
                    'supplier_name' => $snapshot['display_name'] ?? null,
                    'supplier_type' => SupplierPartyTypeEnum::EXTERNAL->value,
                ],
            ];
        }

        return $risks;
    }

    public function createPendingForDecision(
        SupplierProposalDecision $decision,
        array $risks,
        ?int $requestedBy = null
    ): array {
        $reasonCodes = collect($risks)
            ->pluck('reason_code')
            ->filter()
            ->unique()
            ->values()
            ->all();

        ProcurementApproval::query()
            ->where('organization_id', $decision->organization_id)
            ->where('approvable_type', $decision->getMorphClass())
            ->where('approvable_id', $decision->id)
            ->whereIn('status', [
                ProcurementApprovalStatusEnum::PENDING->value,
                ProcurementApprovalStatusEnum::REJECTED->value,
            ])
            ->when(
                $reasonCodes !== [],
                fn ($query) => $query->whereNotIn('reason_code', $reasonCodes)
            )
            ->update([
                'status' => ProcurementApprovalStatusEnum::CANCELLED->value,
                'resolved_at' => now(),
            ]);

        $approvals = [];

        foreach ($risks as $risk) {
            $approval = ProcurementApproval::query()
                ->where('organization_id', $decision->organization_id)
                ->where('approvable_type', $decision->getMorphClass())
                ->where('approvable_id', $decision->id)
                ->where('reason_code', $risk['reason_code'])
                ->first();

            if ($approval === null) {
                $approval = new ProcurementApproval([
                    'organization_id' => $decision->organization_id,
                    'approvable_type' => $decision->getMorphClass(),
                    'approvable_id' => $decision->id,
                    'reason_code' => $risk['reason_code'],
                ]);
            }

            $approval->fill([
                'status' => ProcurementApprovalStatusEnum::PENDING,
                'requested_by' => $requestedBy,
                'approved_by' => null,
                'rejected_by' => null,
                'requested_at' => now(),
                'resolved_at' => null,
                'comment' => null,
                'context' => $risk['context'] ?? [],
            ]);
            $approval->save();

            $approvals[] = $approval->fresh();
        }

        return $approvals;
    }

    public function approve(ProcurementApproval $approval, int $actorId, ?string $comment = null): ProcurementApproval
    {
        return DB::transaction(function () use ($approval, $actorId, $comment): ProcurementApproval {
            $lockedApproval = ProcurementApproval::query()
                ->whereKey($approval->id)
                ->lockForUpdate()
                ->firstOrFail();

            $decision = $this->lockDecisionForApproval($lockedApproval);
            $this->ensurePending($lockedApproval);

            $lockedApproval->update([
                'status' => ProcurementApprovalStatusEnum::APPROVED,
                'approved_by' => $actorId,
                'rejected_by' => null,
                'resolved_at' => now(),
                'comment' => $comment,
            ]);

            $blockingApprovalsExist = ProcurementApproval::query()
                ->where('organization_id', $lockedApproval->organization_id)
                ->where('approvable_type', $lockedApproval->approvable_type)
                ->where('approvable_id', $lockedApproval->approvable_id)
                ->whereIn('status', [
                    ProcurementApprovalStatusEnum::PENDING->value,
                    ProcurementApprovalStatusEnum::REJECTED->value,
                ])
                ->exists();

            if (!$blockingApprovalsExist) {
                $decision->update([
                    'status' => SupplierProposalDecisionEnum::APPROVED,
                ]);
            }

            return $lockedApproval->fresh(['requestedBy', 'approvedBy', 'rejectedBy']);
        });
    }

    public function reject(ProcurementApproval $approval, int $actorId, ?string $comment = null): ProcurementApproval
    {
        return DB::transaction(function () use ($approval, $actorId, $comment): ProcurementApproval {
            $lockedApproval = ProcurementApproval::query()
                ->whereKey($approval->id)
                ->lockForUpdate()
                ->firstOrFail();

            $decision = $this->lockDecisionForApproval($lockedApproval);
            $this->ensurePending($lockedApproval);

            $lockedApproval->update([
                'status' => ProcurementApprovalStatusEnum::REJECTED,
                'approved_by' => null,
                'rejected_by' => $actorId,
                'resolved_at' => now(),
                'comment' => $comment,
            ]);

            $decision->update([
                'status' => SupplierProposalDecisionEnum::REJECTED,
            ]);

            return $lockedApproval->fresh(['requestedBy', 'approvedBy', 'rejectedBy']);
        });
    }

    private function lockDecisionForApproval(ProcurementApproval $approval): SupplierProposalDecision
    {
        if ($approval->approvable_type !== (new SupplierProposalDecision())->getMorphClass()) {
            throw new \DomainException(trans_message('procurement.approvals.decision_not_found'));
        }

        $decision = SupplierProposalDecision::query()
            ->where('organization_id', $approval->organization_id)
            ->whereKey($approval->approvable_id)
            ->lockForUpdate()
            ->first();

        if ($decision === null) {
            throw new \DomainException(trans_message('procurement.approvals.decision_not_found'));
        }

        return $decision;
    }

    private function ensurePending(ProcurementApproval $approval): void
    {
        if ($approval->status !== ProcurementApprovalStatusEnum::PENDING) {
            throw new \DomainException(trans_message('procurement.approvals.invalid_status'));
        }
    }

    private function selectedComparisonTotal(SupplierProposal $selectedProposal, array $comparison): float
    {
        $selectedRow = collect($comparison['rows'] ?? [])
            ->firstWhere('id', $selectedProposal->id);

        if (is_array($selectedRow) && array_key_exists('comparison_total', $selectedRow)) {
            return round((float) $selectedRow['comparison_total'], 2);
        }

        $componentTotal = (float) $selectedProposal->subtotal_amount
            + (float) $selectedProposal->delivery_amount
            + (float) $selectedProposal->vat_amount;

        if ($componentTotal > 0.0) {
            return round($componentTotal, 2);
        }

        return round((float) $selectedProposal->total_amount, 2);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
