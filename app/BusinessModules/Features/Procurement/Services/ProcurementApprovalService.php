<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\ProcurementApprovalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierPartyTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\ProcurementApprovalPolicy;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

class ProcurementApprovalService
{
    public const REASON_BUDGET_EXCEEDED = 'budget_exceeded';
    public const REASON_NON_LOWEST_PRICE = 'non_lowest_price';
    public const REASON_EXTERNAL_SUPPLIER_MISSING_IDENTITY = 'external_supplier_missing_identity';
    public const REASON_ORDER_CHANGED_AFTER_ACCEPTANCE = 'order_changed_after_acceptance';

    public function __construct(
        private readonly ProcurementAuditService $auditService,
        private readonly ProcurementApprovalPolicyService $policyService,
        private readonly ProcurementDutySeparationService $dutySeparationService,
        private readonly AuthorizationService $authorizationService
    ) {}

    public function evaluateForDecision(
        SupplierProposalDecision $decision,
        SupplierProposal $selectedProposal,
        array $comparison
    ): array {
        $risks = [];
        $policy = $this->policyService->resolveForOrganization((int) $decision->organization_id);
        $selectedTotal = $this->selectedComparisonTotal($selectedProposal, $comparison);
        $supplierRequest = $decision->supplierRequest()
            ->with('purchaseRequest')
            ->first();
        $budgetAmount = $supplierRequest?->purchaseRequest?->budget_amount;

        if (
            $policy->is_active
            && $budgetAmount !== null
            && $selectedTotal > ((float) $budgetAmount + (float) $policy->budget_exceed_amount)
        ) {
            $risks[] = [
                'reason_code' => self::REASON_BUDGET_EXCEEDED,
                'context' => [
                    'approval_policy_id' => $policy->id,
                    'selected_supplier_proposal_id' => $selectedProposal->id,
                    'selected_total' => $selectedTotal,
                    'budget_amount' => (float) $budgetAmount,
                    'budget_exceed_amount' => (float) $policy->budget_exceed_amount,
                    'currency' => $selectedProposal->currency,
                ],
            ];
        }

        $cheapestTotal = $this->cheapestComparisonTotal($comparison);
        $deltaAmount = $cheapestTotal === null ? null : round($selectedTotal - $cheapestTotal, 2);
        $deltaPercent = $cheapestTotal === null || $cheapestTotal <= 0.0
            ? null
            : round(($selectedTotal - $cheapestTotal) / $cheapestTotal * 100, 2);

        if (
            $policy->is_active
            && !$decision->is_lowest_price_selected
            && $this->nonLowestThresholdExceeded($policy, $deltaAmount, $deltaPercent)
        ) {
            $risks[] = [
                'reason_code' => self::REASON_NON_LOWEST_PRICE,
                'context' => [
                    'approval_policy_id' => $policy->id,
                    'selected_supplier_proposal_id' => $selectedProposal->id,
                    'cheapest_supplier_proposal_id' => $comparison['cheapest_supplier_proposal_id'] ?? null,
                    'selected_total' => $selectedTotal,
                    'cheapest_total' => $cheapestTotal,
                    'delta_amount' => $deltaAmount,
                    'delta_percent' => $deltaPercent,
                    'non_lowest_delta_amount' => (float) $policy->non_lowest_delta_amount,
                    'non_lowest_delta_percent' => (float) $policy->non_lowest_delta_percent,
                    'currency' => $selectedProposal->currency,
                ],
            ];
        }

        $snapshot = is_array($selectedProposal->supplier_snapshot) ? $selectedProposal->supplier_snapshot : [];
        if (
            $policy->is_active
            && $policy->external_supplier_requires_identity
            &&
            ($snapshot['type'] ?? null) === SupplierPartyTypeEnum::EXTERNAL->value
            && $this->blank($snapshot['tax_id'] ?? null)
        ) {
            $risks[] = [
                'reason_code' => self::REASON_EXTERNAL_SUPPLIER_MISSING_IDENTITY,
                'context' => [
                    'approval_policy_id' => $policy->id,
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
        $decision->loadMissing('winningProposal');
        $supplierPartyId = $decision->winningProposal?->supplier_party_id;
        $supplierSnapshot = is_array($decision->winningProposal?->supplier_snapshot)
            ? $decision->winningProposal->supplier_snapshot
            : [];

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
                    'approval_policy_id' => $risk['context']['approval_policy_id'] ?? null,
                    'approvable_type' => $decision->getMorphClass(),
                    'approvable_id' => $decision->id,
                    'reason_code' => $risk['reason_code'],
                ]);
            }

            $approval->fill([
                'approval_policy_id' => $risk['context']['approval_policy_id'] ?? null,
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

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::PROCUREMENT_APPROVAL_REQUESTED->value,
                $decision,
                (int) $decision->organization_id,
                $requestedBy,
                $supplierPartyId,
                [
                    'approval_id' => $approval->id,
                    'reason_code' => $approval->reason_code,
                    'status' => $approval->status->value,
                    'decision_id' => $decision->id,
                    'selected_supplier_proposal_id' => $decision->winning_supplier_proposal_id,
                    'supplier_name' => $supplierSnapshot['display_name'] ?? null,
                    'context' => $approval->context,
                ]
            );

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
            $this->ensureWinningProposalIsActual($decision);
            $policy = $this->policyService->resolveForOrganization((int) $lockedApproval->organization_id);
            $this->ensurePolicyPermission($policy, $actorId, (int) $lockedApproval->organization_id);
            $this->dutySeparationService->ensureCanResolve(
                $lockedApproval,
                $decision,
                $policy,
                $actorId
            );

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

            $decision->loadMissing('winningProposal');
            $snapshot = is_array($decision->winningProposal?->supplier_snapshot)
                ? $decision->winningProposal->supplier_snapshot
                : [];

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::PROCUREMENT_APPROVAL_APPROVED->value,
                $decision,
                (int) $decision->organization_id,
                $actorId,
                $decision->winningProposal?->supplier_party_id,
                [
                    'approval_id' => $lockedApproval->id,
                    'reason_code' => $lockedApproval->reason_code,
                    'status' => ProcurementApprovalStatusEnum::APPROVED->value,
                    'decision_id' => $decision->id,
                    'decision_status' => $decision->status->value,
                    'selected_supplier_proposal_id' => $decision->winning_supplier_proposal_id,
                    'supplier_name' => $snapshot['display_name'] ?? null,
                    'comment' => $comment,
                ]
            );

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
            $this->ensureWinningProposalIsActual($decision);
            $policy = $this->policyService->resolveForOrganization((int) $lockedApproval->organization_id);
            $this->ensurePolicyPermission($policy, $actorId, (int) $lockedApproval->organization_id);
            $this->dutySeparationService->ensureCanResolve(
                $lockedApproval,
                $decision,
                $policy,
                $actorId
            );

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

            $decision->loadMissing('winningProposal');
            $snapshot = is_array($decision->winningProposal?->supplier_snapshot)
                ? $decision->winningProposal->supplier_snapshot
                : [];

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::PROCUREMENT_APPROVAL_REJECTED->value,
                $decision,
                (int) $decision->organization_id,
                $actorId,
                $decision->winningProposal?->supplier_party_id,
                [
                    'approval_id' => $lockedApproval->id,
                    'reason_code' => $lockedApproval->reason_code,
                    'status' => ProcurementApprovalStatusEnum::REJECTED->value,
                    'decision_id' => $decision->id,
                    'decision_status' => SupplierProposalDecisionEnum::REJECTED->value,
                    'selected_supplier_proposal_id' => $decision->winning_supplier_proposal_id,
                    'supplier_name' => $snapshot['display_name'] ?? null,
                    'comment' => $comment,
                ]
            );

            return $lockedApproval->fresh(['requestedBy', 'approvedBy', 'rejectedBy']);
        });
    }

    public function resolutionBlockers(ProcurementApproval $approval, int $actorId): array
    {
        $decision = $approval->approvable instanceof SupplierProposalDecision
            ? $approval->approvable
            : null;

        if ($decision === null) {
            return [];
        }

        $blockers = [];
        $proposalBlocker = $this->expiredWinningProposalBlocker($decision);

        if ($proposalBlocker !== null) {
            $blockers[] = $proposalBlocker;
        }

        return [
            ...$blockers,
            ...$this->dutySeparationService->resolutionBlockers(
                $approval,
                $decision,
                $this->policyService->resolveForOrganization((int) $approval->organization_id),
                $actorId
            ),
        ];
    }

    public function canResolveByPolicy(ProcurementApproval $approval, int $actorId): bool
    {
        return $this->hasPolicyPermission(
            $this->policyService->resolveForOrganization((int) $approval->organization_id),
            $actorId,
            (int) $approval->organization_id
        );
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

    private function ensureWinningProposalIsActual(SupplierProposalDecision $decision): void
    {
        if ($this->expiredWinningProposalBlocker($decision) !== null) {
            throw new \DomainException(trans_message('procurement.approvals.proposal_expired'));
        }
    }

    private function expiredWinningProposalBlocker(SupplierProposalDecision $decision): ?array
    {
        $decision->loadMissing('winningProposal');
        $proposal = $decision->winningProposal;

        if (!$proposal instanceof SupplierProposal) {
            return null;
        }

        if ($proposal->status !== SupplierProposalStatusEnum::EXPIRED && !$proposal->isExpired()) {
            return null;
        }

        return [
            'code' => 'proposal_expired',
            'message' => trans_message('procurement.approvals.proposal_expired'),
        ];
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

    private function cheapestComparisonTotal(array $comparison): ?float
    {
        $cheapestProposalId = $comparison['cheapest_supplier_proposal_id'] ?? null;

        if ($cheapestProposalId === null) {
            return null;
        }

        $cheapestRow = collect($comparison['rows'] ?? [])
            ->firstWhere('id', $cheapestProposalId);

        if (!is_array($cheapestRow) || !array_key_exists('comparison_total', $cheapestRow)) {
            return null;
        }

        return round((float) $cheapestRow['comparison_total'], 2);
    }

    private function nonLowestThresholdExceeded(
        ProcurementApprovalPolicy $policy,
        ?float $deltaAmount,
        ?float $deltaPercent
    ): bool {
        if ($deltaAmount === null) {
            return true;
        }

        $amountExceeded = $deltaAmount >= (float) $policy->non_lowest_delta_amount;
        $percentExceeded = $deltaPercent === null || $deltaPercent >= (float) $policy->non_lowest_delta_percent;

        return $amountExceeded || $percentExceeded;
    }

    private function ensurePolicyPermission(ProcurementApprovalPolicy $policy, int $actorId, int $organizationId): void
    {
        if ($this->hasPolicyPermission($policy, $actorId, $organizationId)) {
            return;
        }

        throw ValidationException::withMessages([
            'approval' => [trans_message('procurement.access_denied')],
        ]);
    }

    private function hasPolicyPermission(ProcurementApprovalPolicy $policy, int $actorId, int $organizationId): bool
    {
        $permission = trim((string) $policy->required_approval_permission);

        if ($permission === '' || $permission === 'procurement.approvals.resolve') {
            return true;
        }

        $user = User::query()->find($actorId);

        if (!$user instanceof User) {
            return false;
        }

        return $this->authorizationService->can($user, $permission, [
            'organization_id' => $organizationId,
        ]);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
