<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Http\Resources\SupplierProposalDecisionResource;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

class SupplierProposalComparisonService
{
    public function __construct(
        private readonly ProcurementApprovalService $approvalService,
        private readonly ProcurementAuditService $auditService
    ) {}

    public function comparisonForRequest(SupplierRequest $supplierRequest, bool $includeDecision = true): array
    {
        $proposals = $supplierRequest->proposals()
            ->with(['lines', 'supplier', 'externalSupplierContact', 'supplierParty', 'currentVersion', 'supplierRequestVersion'])
            ->where('organization_id', $supplierRequest->organization_id)
            ->whereIn('status', [
                SupplierProposalStatusEnum::SUBMITTED->value,
                SupplierProposalStatusEnum::ACCEPTED->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now()->toDateString());
            })
            ->orderBy('id')
            ->get();

        $decision = $includeDecision ? $this->decisionForSupplierRequest($supplierRequest) : null;

        return $this->comparisonPayload($proposals, [
            'supplier_request_id' => $supplierRequest->id,
        ], true, $decision);
    }

    public function comparisonForPurchaseRequest(PurchaseRequest $purchaseRequest, bool $includeDecision = true): array
    {
        $proposals = SupplierProposal::query()
            ->with(['lines', 'supplier', 'externalSupplierContact', 'supplierParty', 'currentVersion', 'supplierRequestVersion'])
            ->where('organization_id', $purchaseRequest->organization_id)
            ->whereHas('supplierRequest', static function ($query) use ($purchaseRequest): void {
                $query->where('purchase_request_id', $purchaseRequest->id);
            })
            ->whereIn('status', [
                SupplierProposalStatusEnum::SUBMITTED->value,
                SupplierProposalStatusEnum::ACCEPTED->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now()->toDateString());
            })
            ->orderBy('id')
            ->get();

        $decision = $includeDecision ? $this->decisionForPurchaseRequest($purchaseRequest) : null;

        return $this->comparisonPayload($proposals, [
            'purchase_request_id' => $purchaseRequest->id,
        ], false, $decision);
    }

    private function comparisonPayload(
        $proposals,
        array $scope,
        bool $requireSameSupplierRequestVersion,
        ?SupplierProposalDecision $decision
    ): array
    {
        $rows = $proposals
            ->map(fn (SupplierProposal $proposal): array => $this->proposalComparisonRow($proposal))
            ->values()
            ->all();

        $baseCurrency = $rows[0]['currency'] ?? null;
        $baseRequestVersionId = $rows[0]['supplier_request_version_id'] ?? null;

        $rows = collect($rows)
            ->map(function (array $row) use ($baseCurrency, $baseRequestVersionId, $requireSameSupplierRequestVersion): array {
                $row['is_directly_comparable'] = $baseCurrency === null || $row['currency'] === $baseCurrency;
                $row['comparison_warnings'] = [];

                if (!$row['is_directly_comparable']) {
                    $row['comparison_warnings'][] = trans_message('procurement_enterprise.proposal_decisions.currency_not_comparable');
                }

                if (
                    $requireSameSupplierRequestVersion
                    && $baseRequestVersionId !== null
                    && $row['supplier_request_version_id'] !== $baseRequestVersionId
                ) {
                    $row['is_directly_comparable'] = false;
                    $row['comparison_warnings'][] = trans_message('procurement_enterprise.proposal_decisions.request_version_not_comparable');
                }

                return $row;
            })
            ->values()
            ->all();

        $cheapestRow = collect($rows)
            ->filter(fn (array $row): bool => (bool) ($row['is_directly_comparable'] ?? true))
            ->sortBy([
                ['comparison_total', 'asc'],
                ['id', 'asc'],
            ])
            ->first();

        $cheapestProposalId = $cheapestRow['id'] ?? null;

        $rows = collect($rows)
            ->map(function (array $row) use ($cheapestProposalId): array {
                $row['is_cheapest'] = $cheapestProposalId !== null && $row['id'] === $cheapestProposalId;

                return $row;
            })
            ->values()
            ->all();

        return array_merge($scope, [
            'supplier_request_version_ids' => collect($rows)
                ->pluck('supplier_request_version_id')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'cheapest_supplier_proposal_id' => $cheapestProposalId,
            'decision' => $this->decisionPayload($decision),
            'rows' => $rows,
        ]);
    }

    private function decisionForSupplierRequest(SupplierRequest $supplierRequest): ?SupplierProposalDecision
    {
        return SupplierProposalDecision::query()
            ->with($this->decisionRelations())
            ->where('organization_id', $supplierRequest->organization_id)
            ->where('supplier_request_id', $supplierRequest->id)
            ->latest('id')
            ->first();
    }

    private function decisionForPurchaseRequest(PurchaseRequest $purchaseRequest): ?SupplierProposalDecision
    {
        return SupplierProposalDecision::query()
            ->with($this->decisionRelations())
            ->where('organization_id', $purchaseRequest->organization_id)
            ->whereIn('supplier_request_id', static function ($query) use ($purchaseRequest): void {
                $query->select('id')
                    ->from('supplier_requests')
                    ->where('purchase_request_id', $purchaseRequest->id)
                    ->whereNull('deleted_at');
            })
            ->latest('id')
            ->first();
    }

    private function decisionPayload(?SupplierProposalDecision $decision): ?array
    {
        if ($decision === null) {
            return null;
        }

        return (new SupplierProposalDecisionResource($decision))->resolve();
    }

    private function decisionRelations(): array
    {
        return [
            'winningProposal.currentVersion',
            'cheapestProposal.currentVersion',
            'winningProposalVersion',
            'cheapestProposalVersion',
            'selectedBy',
            'approvals',
        ];
    }

    public function selectWinner(
        SupplierRequest $supplierRequest,
        int $proposalId,
        ?string $reason,
        ?int $actorId
    ): SupplierProposalDecision {
        return DB::transaction(function () use ($supplierRequest, $proposalId, $reason, $actorId): SupplierProposalDecision {
            $lockedSupplierRequest = SupplierRequest::query()
                ->whereKey($supplierRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $decision = SupplierProposalDecision::query()
                ->where('organization_id', $lockedSupplierRequest->organization_id)
                ->where('supplier_request_id', $lockedSupplierRequest->id)
                ->lockForUpdate()
                ->first();

            $this->ensureDecisionCanBeChanged($lockedSupplierRequest);

            $proposal = $this->findComparableProposal($lockedSupplierRequest, $proposalId);
            $comparison = $this->comparisonForRequest($lockedSupplierRequest, false);
            $cheapestProposalId = $comparison['cheapest_supplier_proposal_id'];
            $selectedRow = collect($comparison['rows'])->firstWhere('id', $proposal->id);

            if ($cheapestProposalId === null) {
                throw ValidationException::withMessages([
                    'proposal_id' => [trans_message('procurement.proposal_decisions.proposal_not_comparable')],
                ]);
            }

            if (!is_array($selectedRow) || !($selectedRow['is_directly_comparable'] ?? true)) {
                throw ValidationException::withMessages([
                    'proposal_id' => [trans_message('procurement.proposal_decisions.proposal_not_comparable')],
                ]);
            }

            $isLowestPriceSelected = $proposal->id === $cheapestProposalId;
            $normalizedReason = $this->normalizeReason($reason);
            $cheapestRow = collect($comparison['rows'])->firstWhere('id', $cheapestProposalId);
            $cheapestProposalVersionId = is_array($cheapestRow)
                ? ($cheapestRow['current_version_id'] ?? null)
                : null;

            if (!$isLowestPriceSelected && $normalizedReason === null) {
                throw ValidationException::withMessages([
                    'decision_reason' => [trans_message('procurement.proposal_decisions.reason_required')],
                ]);
            }

            if ($decision === null) {
                $decision = new SupplierProposalDecision([
                    'supplier_request_id' => $lockedSupplierRequest->id,
                ]);
            }

            $decision->fill([
                'organization_id' => $lockedSupplierRequest->organization_id,
                'winning_supplier_proposal_id' => $proposal->id,
                'winning_supplier_proposal_version_id' => $proposal->currentVersion?->id,
                'cheapest_supplier_proposal_id' => $cheapestProposalId,
                'cheapest_supplier_proposal_version_id' => $cheapestProposalVersionId,
                'status' => SupplierProposalDecisionEnum::SELECTED,
                'is_lowest_price_selected' => $isLowestPriceSelected,
                'decision_reason' => $normalizedReason,
                'comparison_snapshot' => $comparison,
                'selected_by' => $actorId,
                'selected_at' => now(),
            ]);

            $risks = $this->approvalService->evaluateForDecision($decision, $proposal, $comparison);
            $decision->status = $risks === []
                ? SupplierProposalDecisionEnum::SELECTED
                : SupplierProposalDecisionEnum::APPROVAL_REQUIRED;
            $decision->save();

            $snapshot = is_array($proposal->supplier_snapshot) ? $proposal->supplier_snapshot : [];

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::SUPPLIER_PROPOSAL_SELECTED->value,
                $decision,
                (int) $decision->organization_id,
                $actorId,
                $proposal->supplier_party_id,
                [
                    'supplier_request_number' => $lockedSupplierRequest->request_number,
                    'selected_supplier_proposal_number' => $proposal->proposal_number,
                    'selected_supplier_name' => $this->supplierName($proposal, $snapshot),
                    'selected_total' => $this->comparisonTotal($proposal),
                    'currency' => $proposal->currency,
                    'cheapest_supplier_proposal_id' => $cheapestProposalId,
                    'is_lowest_price_selected' => $isLowestPriceSelected,
                    'decision_reason' => $normalizedReason,
                    'status' => $decision->status->value,
                    'approval_reason_codes' => collect($risks)->pluck('reason_code')->values()->all(),
                ]
            );

            $this->approvalService->createPendingForDecision($decision, $risks, $actorId);

            return $decision->fresh([
                'winningProposal.currentVersion',
                'cheapestProposal.currentVersion',
                'winningProposalVersion',
                'cheapestProposalVersion',
                'selectedBy',
                'approvals',
            ]);
        });
    }

    public function selectWinnerForPurchaseRequest(
        PurchaseRequest $purchaseRequest,
        int $proposalId,
        ?string $reason,
        ?int $actorId
    ): SupplierProposalDecision {
        return DB::transaction(function () use ($purchaseRequest, $proposalId, $reason, $actorId): SupplierProposalDecision {
            $lockedPurchaseRequest = PurchaseRequest::query()
                ->whereKey($purchaseRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensurePurchaseRequestDecisionCanBeChanged($lockedPurchaseRequest);

            $proposal = $this->findComparableProposalForPurchaseRequest($lockedPurchaseRequest, $proposalId);
            $supplierRequest = SupplierRequest::query()
                ->where('organization_id', $lockedPurchaseRequest->organization_id)
                ->whereKey($proposal->supplier_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $decision = SupplierProposalDecision::query()
                ->where('organization_id', $lockedPurchaseRequest->organization_id)
                ->whereIn('supplier_request_id', static function ($query) use ($lockedPurchaseRequest): void {
                    $query->select('id')
                        ->from('supplier_requests')
                        ->where('purchase_request_id', $lockedPurchaseRequest->id)
                        ->whereNull('deleted_at');
                })
                ->lockForUpdate()
                ->first();

            $comparison = $this->comparisonForPurchaseRequest($lockedPurchaseRequest, false);
            $cheapestProposalId = $comparison['cheapest_supplier_proposal_id'];
            $selectedRow = collect($comparison['rows'])->firstWhere('id', $proposal->id);

            if ($cheapestProposalId === null) {
                throw ValidationException::withMessages([
                    'proposal_id' => [trans_message('procurement.proposal_decisions.proposal_not_comparable')],
                ]);
            }

            if (!is_array($selectedRow) || !($selectedRow['is_directly_comparable'] ?? true)) {
                throw ValidationException::withMessages([
                    'proposal_id' => [trans_message('procurement.proposal_decisions.proposal_not_comparable')],
                ]);
            }

            $isLowestPriceSelected = $proposal->id === $cheapestProposalId;
            $normalizedReason = $this->normalizeReason($reason);
            $cheapestRow = collect($comparison['rows'])->firstWhere('id', $cheapestProposalId);
            $cheapestProposalVersionId = is_array($cheapestRow)
                ? ($cheapestRow['current_version_id'] ?? null)
                : null;

            if (!$isLowestPriceSelected && $normalizedReason === null) {
                throw ValidationException::withMessages([
                    'decision_reason' => [trans_message('procurement.proposal_decisions.reason_required')],
                ]);
            }

            if ($decision === null) {
                $decision = new SupplierProposalDecision([
                    'supplier_request_id' => $supplierRequest->id,
                ]);
            }

            $decision->fill([
                'organization_id' => $lockedPurchaseRequest->organization_id,
                'supplier_request_id' => $supplierRequest->id,
                'winning_supplier_proposal_id' => $proposal->id,
                'winning_supplier_proposal_version_id' => $proposal->currentVersion?->id,
                'cheapest_supplier_proposal_id' => $cheapestProposalId,
                'cheapest_supplier_proposal_version_id' => $cheapestProposalVersionId,
                'status' => SupplierProposalDecisionEnum::SELECTED,
                'is_lowest_price_selected' => $isLowestPriceSelected,
                'decision_reason' => $normalizedReason,
                'comparison_snapshot' => $comparison,
                'selected_by' => $actorId,
                'selected_at' => now(),
            ]);

            $risks = $this->approvalService->evaluateForDecision($decision, $proposal, $comparison);
            $decision->status = $risks === []
                ? SupplierProposalDecisionEnum::SELECTED
                : SupplierProposalDecisionEnum::APPROVAL_REQUIRED;
            $decision->save();

            $snapshot = is_array($proposal->supplier_snapshot) ? $proposal->supplier_snapshot : [];

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::SUPPLIER_PROPOSAL_SELECTED->value,
                $decision,
                (int) $decision->organization_id,
                $actorId,
                $proposal->supplier_party_id,
                [
                    'purchase_request_number' => $lockedPurchaseRequest->request_number,
                    'supplier_request_number' => $supplierRequest->request_number,
                    'selected_supplier_proposal_number' => $proposal->proposal_number,
                    'selected_supplier_name' => $this->supplierName($proposal, $snapshot),
                    'selected_total' => $this->comparisonTotal($proposal),
                    'currency' => $proposal->currency,
                    'cheapest_supplier_proposal_id' => $cheapestProposalId,
                    'is_lowest_price_selected' => $isLowestPriceSelected,
                    'decision_reason' => $normalizedReason,
                    'status' => $decision->status->value,
                    'approval_reason_codes' => collect($risks)->pluck('reason_code')->values()->all(),
                ]
            );

            $this->approvalService->createPendingForDecision($decision, $risks, $actorId);

            return $decision->fresh([
                'winningProposal.currentVersion',
                'cheapestProposal.currentVersion',
                'winningProposalVersion',
                'cheapestProposalVersion',
                'selectedBy',
                'approvals',
            ]);
        });
    }

    public function hasSelectedDecisionForProposal(SupplierProposal $proposal): bool
    {
        if ($proposal->supplier_request_id === null) {
            return false;
        }

        return SupplierProposalDecision::query()
            ->where('organization_id', $proposal->organization_id)
            ->where('supplier_request_id', $proposal->supplier_request_id)
            ->where('winning_supplier_proposal_id', $proposal->id)
            ->whereIn('status', [
                SupplierProposalDecisionEnum::SELECTED->value,
                SupplierProposalDecisionEnum::APPROVED->value,
            ])
            ->exists();
    }

    private function findComparableProposal(SupplierRequest $supplierRequest, int $proposalId): SupplierProposal
    {
        $proposal = SupplierProposal::query()
            ->with('currentVersion')
            ->where('organization_id', $supplierRequest->organization_id)
            ->where('supplier_request_id', $supplierRequest->id)
            ->where('id', $proposalId)
            ->lockForUpdate()
            ->whereIn('status', [
                SupplierProposalStatusEnum::SUBMITTED->value,
                SupplierProposalStatusEnum::ACCEPTED->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now()->toDateString());
            })
            ->first();

        if ($proposal === null) {
            throw ValidationException::withMessages([
                'proposal_id' => [trans_message('procurement.proposal_decisions.proposal_not_comparable')],
            ]);
        }

        return $proposal;
    }

    private function findComparableProposalForPurchaseRequest(PurchaseRequest $purchaseRequest, int $proposalId): SupplierProposal
    {
        $proposal = SupplierProposal::query()
            ->with('currentVersion')
            ->where('organization_id', $purchaseRequest->organization_id)
            ->where('id', $proposalId)
            ->whereHas('supplierRequest', static function ($query) use ($purchaseRequest): void {
                $query->where('purchase_request_id', $purchaseRequest->id);
            })
            ->lockForUpdate()
            ->whereIn('status', [
                SupplierProposalStatusEnum::SUBMITTED->value,
                SupplierProposalStatusEnum::ACCEPTED->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now()->toDateString());
            })
            ->first();

        if ($proposal === null) {
            throw ValidationException::withMessages([
                'proposal_id' => [trans_message('procurement.proposal_decisions.proposal_not_comparable')],
            ]);
        }

        return $proposal;
    }

    private function ensurePurchaseRequestDecisionCanBeChanged(PurchaseRequest $purchaseRequest): void
    {
        $acceptedProposal = SupplierProposal::query()
            ->where('organization_id', $purchaseRequest->organization_id)
            ->whereHas('supplierRequest', static function ($query) use ($purchaseRequest): void {
                $query->where('purchase_request_id', $purchaseRequest->id);
            })
            ->where('status', SupplierProposalStatusEnum::ACCEPTED->value)
            ->lockForUpdate()
            ->first();

        $purchaseOrder = DB::table('purchase_orders')
            ->join('supplier_proposals', 'supplier_proposals.id', '=', 'purchase_orders.accepted_supplier_proposal_id')
            ->join('supplier_requests', 'supplier_requests.id', '=', 'supplier_proposals.supplier_request_id')
            ->where('supplier_proposals.organization_id', $purchaseRequest->organization_id)
            ->where('supplier_requests.purchase_request_id', $purchaseRequest->id)
            ->whereNull('purchase_orders.deleted_at')
            ->lockForUpdate()
            ->first();

        if ($acceptedProposal !== null || $purchaseOrder !== null) {
            throw ValidationException::withMessages([
                'proposal_id' => [trans_message('procurement.proposal_decisions.already_finalized')],
            ]);
        }
    }

    private function ensureDecisionCanBeChanged(SupplierRequest $supplierRequest): void
    {
        $acceptedProposal = SupplierProposal::query()
            ->where('organization_id', $supplierRequest->organization_id)
            ->where('supplier_request_id', $supplierRequest->id)
            ->where('status', SupplierProposalStatusEnum::ACCEPTED->value)
            ->lockForUpdate()
            ->first();

        $purchaseOrder = DB::table('purchase_orders')
            ->join('supplier_proposals', 'supplier_proposals.id', '=', 'purchase_orders.accepted_supplier_proposal_id')
            ->where('supplier_proposals.organization_id', $supplierRequest->organization_id)
            ->where('supplier_proposals.supplier_request_id', $supplierRequest->id)
            ->whereNull('purchase_orders.deleted_at')
            ->lockForUpdate()
            ->first();

        if ($acceptedProposal !== null || $purchaseOrder !== null) {
            throw ValidationException::withMessages([
                'proposal_id' => [trans_message('procurement.proposal_decisions.already_finalized')],
            ]);
        }
    }

    private function proposalComparisonRow(SupplierProposal $proposal): array
    {
        $snapshot = is_array($proposal->supplier_snapshot) ? $proposal->supplier_snapshot : [];
        $commercial = $this->commercialSnapshot($proposal);

        return [
            'id' => $proposal->id,
            'current_version_id' => $proposal->currentVersion?->id,
            'current_version_number' => $proposal->currentVersion?->version_number,
            'supplier_request_id' => $proposal->supplier_request_id,
            'supplier_request_version_id' => $proposal->supplier_request_version_id,
            'supplier_request_version_number' => $proposal->supplierRequestVersion?->version_number,
            'supplier_id' => $proposal->supplier_id,
            'external_supplier_contact_id' => $proposal->external_supplier_contact_id,
            'supplier_party_id' => $proposal->supplier_party_id,
            'supplier_name' => $this->supplierName($proposal, $snapshot),
            'supplier_snapshot' => $snapshot,
            'proposal_number' => $proposal->proposal_number,
            'proposal_date' => $proposal->proposal_date?->format('Y-m-d'),
            'status' => $proposal->status->value,
            'subtotal_amount' => (float) $commercial['subtotal_amount'],
            'delivery_amount' => (float) $commercial['delivery_amount'],
            'vat_amount' => (float) $commercial['vat_amount'],
            'total_amount' => (float) $commercial['total_amount'],
            'comparison_total' => $this->comparisonTotal($proposal),
            'currency' => (string) $commercial['currency'],
            'vat_mode' => $commercial['vat_mode'] ?? null,
            'vat_rate' => $commercial['vat_rate'] ?? null,
            'valid_until' => $commercial['valid_until'] ?? null,
            'delivery_due_date' => $commercial['delivery_due_date'] ?? null,
            'lead_time_days' => $commercial['lead_time_days'] ?? null,
            'payment_terms' => $commercial['payment_terms'] ?? null,
            'delivery_terms' => $commercial['delivery_terms'] ?? null,
            'warranty_terms' => $commercial['warranty_terms'] ?? null,
            'is_expired' => $proposal->isExpired(),
            'lines' => $proposal->lines->map(fn ($line): array => [
                'id' => $line->id,
                'supplier_request_line_id' => $line->supplier_request_line_id,
                'material_id' => $line->material_id,
                'name' => $line->name,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'unit_price' => (float) $line->unit_price,
                'total_amount' => (float) $line->total_amount,
                'comment' => $line->comment,
            ])->values()->all(),
        ];
    }

    private function comparisonTotal(SupplierProposal $proposal): float
    {
        $commercial = $this->commercialSnapshot($proposal);
        $componentTotal = (float) $commercial['subtotal_amount']
            + (float) $commercial['delivery_amount']
            + (float) $commercial['vat_amount'];

        if ($componentTotal > 0.0) {
            return round($componentTotal, 2);
        }

        return round((float) $commercial['total_amount'], 2);
    }

    private function commercialSnapshot(SupplierProposal $proposal): array
    {
        $snapshot = $proposal->currentVersion?->commercial_snapshot;

        if (is_array($snapshot) && $snapshot !== []) {
            return array_merge([
                'subtotal_amount' => 0,
                'delivery_amount' => 0,
                'vat_amount' => 0,
                'total_amount' => 0,
                'currency' => $proposal->currency,
                'vat_mode' => $proposal->vat_mode,
                'vat_rate' => $proposal->vat_rate === null ? null : (float) $proposal->vat_rate,
                'valid_until' => $proposal->valid_until?->format('Y-m-d'),
                'delivery_due_date' => $proposal->delivery_due_date?->format('Y-m-d'),
                'lead_time_days' => $proposal->lead_time_days,
                'payment_terms' => $proposal->payment_terms,
                'delivery_terms' => $proposal->delivery_terms,
                'warranty_terms' => $proposal->warranty_terms,
            ], $snapshot);
        }

        return [
            'subtotal_amount' => (float) $proposal->subtotal_amount,
            'delivery_amount' => (float) $proposal->delivery_amount,
            'vat_amount' => (float) $proposal->vat_amount,
            'total_amount' => (float) $proposal->total_amount,
            'currency' => $proposal->currency,
            'vat_mode' => $proposal->vat_mode,
            'vat_rate' => $proposal->vat_rate === null ? null : (float) $proposal->vat_rate,
            'valid_until' => $proposal->valid_until?->format('Y-m-d'),
            'delivery_due_date' => $proposal->delivery_due_date?->format('Y-m-d'),
            'lead_time_days' => $proposal->lead_time_days,
            'payment_terms' => $proposal->payment_terms,
            'delivery_terms' => $proposal->delivery_terms,
            'warranty_terms' => $proposal->warranty_terms,
        ];
    }

    private function supplierName(SupplierProposal $proposal, array $snapshot): ?string
    {
        if (($snapshot['display_name'] ?? null) !== null) {
            return (string) $snapshot['display_name'];
        }

        if ($proposal->relationLoaded('supplier') && $proposal->supplier !== null) {
            return $proposal->supplier->name;
        }

        if ($proposal->relationLoaded('externalSupplierContact') && $proposal->externalSupplierContact !== null) {
            return $proposal->externalSupplierContact->name;
        }

        if ($proposal->relationLoaded('supplierParty') && $proposal->supplierParty !== null) {
            return $proposal->supplierParty->display_name;
        }

        return null;
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }
}
