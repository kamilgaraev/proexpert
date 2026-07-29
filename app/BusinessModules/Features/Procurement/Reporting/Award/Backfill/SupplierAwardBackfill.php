<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardDecisionVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ComparableProposalVersionFactory;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\SupplierAwardDecisionVersionRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\SupplierAwardFormula;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\SupplierProposalComparabilityPolicy;
use App\Support\Reporting\OwnerBackfillBatch;
use Throwable;

final readonly class SupplierAwardBackfill
{
    private const MAX_SLICE = 500;

    public function __construct(
        private ComparableProposalVersionFactory $proposals,
        private SupplierProposalComparabilityPolicy $comparability,
        private SupplierAwardFormula $formula,
        private SupplierAwardDecisionVersionRecorder $versions,
    ) {}

    public function backfillSlice(int $organizationId, int $cursor, int $limit = self::MAX_SLICE): OwnerBackfillBatch
    {
        $limit = min(self::MAX_SLICE, max(1, $limit));
        $decisions = SupplierProposalDecision::query()
            ->where('organization_id', $organizationId)
            ->where('id', '>', $cursor)
            ->whereNotNull('selected_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $input = [];
        $projected = [];
        $gaps = 0;
        foreach ($decisions as $decision) {
            $input[] = [
                'decision_id' => (int) $decision->id,
                'selected_at' => $decision->selected_at?->format(DATE_ATOM),
                'comparison_snapshot' => $decision->comparison_snapshot,
            ];
            try {
                $snapshot = is_array($decision->comparison_snapshot) ? $decision->comparison_snapshot : [];
                $versionIds = collect($snapshot['rows'] ?? [])
                    ->pluck('current_version_id')
                    ->filter(static fn (mixed $id): bool => is_int($id) && $id > 0)
                    ->unique()
                    ->values()
                    ->all();
                $proposalVersions = SupplierProposalVersion::query()
                    ->with('supplierProposal')
                    ->where('organization_id', $organizationId)
                    ->whereIn('id', $versionIds)
                    ->orderBy('id')
                    ->get();
                if ($proposalVersions->count() !== count($versionIds)) {
                    $gaps++;

                    continue;
                }
                $typed = $proposalVersions
                    ->map(fn (SupplierProposalVersion $version) => $this->proposals->make($version))
                    ->values()
                    ->all();
                $selectedVersionId = (int) $decision->winning_supplier_proposal_version_id;
                $partition = $this->comparability->partition($typed, $selectedVersionId);
                $supplierRequest = SupplierRequest::query()
                    ->where('organization_id', $organizationId)
                    ->findOrFail($decision->supplier_request_id);
                $invited = SupplierRequest::query()
                    ->where('organization_id', $organizationId)
                    ->where('purchase_request_id', $supplierRequest->purchase_request_id)
                    ->whereNotNull('supplier_party_id')
                    ->whereNotNull('sent_at')
                    ->where('sent_at', '<=', $decision->selected_at)
                    ->pluck('supplier_party_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
                $metric = $this->formula->calculate($invited, $partition->comparable, $selectedVersionId);
                $sorted = $partition->comparable;
                usort(
                    $sorted,
                    static fn ($left, $right): int => [$left->amountMinor, $left->proposalVersionId]
                        <=> [$right->amountMinor, $right->proposalVersionId],
                );
                $record = $this->versions->record(
                    organizationId: $organizationId,
                    decisionId: (int) $decision->id,
                    decisionVersion: 1,
                    supplierRequestId: (int) $decision->supplier_request_id,
                    selectedProposalVersionId: $selectedVersionId,
                    cheapestProposalVersionId: $sorted[0]->proposalVersionId,
                    medianProposalVersionId: $sorted[intdiv(count($sorted) - 1, 2)]->proposalVersionId,
                    invitedSupplierIds: $invited,
                    comparableProposalVersionIds: array_map(
                        static fn ($proposal): int => $proposal->proposalVersionId,
                        $partition->comparable,
                    ),
                    excludedComparisons: $partition->excludedReasonByProposalVersionId,
                    comparableSetHash: $metric->comparableSetHash,
                    isLowestPriceSelected: $selectedVersionId === $sorted[0]->proposalVersionId,
                    decisionReason: $decision->decision_reason,
                    selectedAt: $decision->selected_at,
                    purchaseRequestId: (int) $supplierRequest->purchase_request_id,
                    selectedBy: $decision->selected_by,
                );
                $projected[] = (int) $record->id;
            } catch (Throwable) {
                $gaps++;
            }
        }
        $nextCursor = $decisions->isEmpty() ? $cursor : (int) $decisions->last()->id;
        $output = SupplierAwardDecisionVersion::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $projected)
            ->orderBy('id')
            ->pluck('source_hash')
            ->all();

        return new OwnerBackfillBatch(
            $decisions->count(),
            count($projected),
            $gaps,
            $nextCursor,
            $decisions->count() < $limit,
            hash('sha256', CanonicalJson::encode($input)),
            hash('sha256', CanonicalJson::encode($output)),
        );
    }
}
