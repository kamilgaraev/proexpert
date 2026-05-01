<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

class SupplierProposalComparisonService
{
    public function comparisonForRequest(SupplierRequest $supplierRequest): array
    {
        $proposals = $supplierRequest->proposals()
            ->with(['lines', 'supplier', 'externalSupplierContact', 'supplierParty'])
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

        $rows = $proposals
            ->map(fn (SupplierProposal $proposal): array => $this->proposalComparisonRow($proposal))
            ->values()
            ->all();

        $cheapestRow = collect($rows)
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

        return [
            'supplier_request_id' => $supplierRequest->id,
            'cheapest_supplier_proposal_id' => $cheapestProposalId,
            'rows' => $rows,
        ];
    }

    public function selectWinner(
        SupplierRequest $supplierRequest,
        int $proposalId,
        ?string $reason,
        ?int $actorId
    ): SupplierProposalDecision {
        return DB::transaction(function () use ($supplierRequest, $proposalId, $reason, $actorId): SupplierProposalDecision {
            $proposal = $this->findComparableProposal($supplierRequest, $proposalId);
            $comparison = $this->comparisonForRequest($supplierRequest);
            $cheapestProposalId = $comparison['cheapest_supplier_proposal_id'];

            if ($cheapestProposalId === null) {
                throw ValidationException::withMessages([
                    'proposal_id' => [trans_message('procurement.proposal_decisions.proposal_not_comparable')],
                ]);
            }

            $isLowestPriceSelected = $proposal->id === $cheapestProposalId;
            $normalizedReason = $this->normalizeReason($reason);

            if (!$isLowestPriceSelected && $normalizedReason === null) {
                throw ValidationException::withMessages([
                    'decision_reason' => [trans_message('procurement.proposal_decisions.reason_required')],
                ]);
            }

            $decision = SupplierProposalDecision::query()->updateOrCreate(
                ['supplier_request_id' => $supplierRequest->id],
                [
                    'organization_id' => $supplierRequest->organization_id,
                    'winning_supplier_proposal_id' => $proposal->id,
                    'cheapest_supplier_proposal_id' => $cheapestProposalId,
                    'status' => SupplierProposalDecisionEnum::SELECTED,
                    'is_lowest_price_selected' => $isLowestPriceSelected,
                    'decision_reason' => $normalizedReason,
                    'comparison_snapshot' => $comparison,
                    'selected_by' => $actorId,
                    'selected_at' => now(),
                ]
            );

            return $decision->fresh(['winningProposal', 'cheapestProposal', 'selectedBy']);
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
            ->where('organization_id', $supplierRequest->organization_id)
            ->where('supplier_request_id', $supplierRequest->id)
            ->where('id', $proposalId)
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

    private function proposalComparisonRow(SupplierProposal $proposal): array
    {
        $snapshot = is_array($proposal->supplier_snapshot) ? $proposal->supplier_snapshot : [];

        return [
            'id' => $proposal->id,
            'supplier_request_id' => $proposal->supplier_request_id,
            'supplier_id' => $proposal->supplier_id,
            'external_supplier_contact_id' => $proposal->external_supplier_contact_id,
            'supplier_party_id' => $proposal->supplier_party_id,
            'supplier_name' => $this->supplierName($proposal, $snapshot),
            'supplier_snapshot' => $snapshot,
            'proposal_number' => $proposal->proposal_number,
            'proposal_date' => $proposal->proposal_date?->format('Y-m-d'),
            'status' => $proposal->status->value,
            'subtotal_amount' => (float) $proposal->subtotal_amount,
            'delivery_amount' => (float) $proposal->delivery_amount,
            'vat_amount' => (float) $proposal->vat_amount,
            'total_amount' => (float) $proposal->total_amount,
            'comparison_total' => $this->comparisonTotal($proposal),
            'currency' => $proposal->currency,
            'valid_until' => $proposal->valid_until?->format('Y-m-d'),
            'payment_terms' => $proposal->payment_terms,
            'delivery_terms' => $proposal->delivery_terms,
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
        $componentTotal = (float) $proposal->subtotal_amount
            + (float) $proposal->delivery_amount
            + (float) $proposal->vat_amount;

        if ($componentTotal > 0.0) {
            return round($componentTotal, 2);
        }

        return round((float) $proposal->total_amount, 2);
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
