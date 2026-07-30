<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardDecisionVersion;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SupplierAwardDecisionVersionRecorder
{
    public function record(
        int $organizationId,
        int $decisionId,
        int $decisionVersion,
        int $supplierRequestId,
        int $selectedProposalVersionId,
        int $cheapestProposalVersionId,
        int $medianProposalVersionId,
        array $invitedSupplierIds,
        array $comparableProposalVersionIds,
        array $excludedComparisons,
        string $comparableSetHash,
        bool $isLowestPriceSelected,
        ?string $decisionReason,
        DateTimeInterface $selectedAt,
        ?int $purchaseRequestId = null,
        ?int $selectedBy = null,
        ?int $projectId = null,
    ): SupplierAwardDecisionVersion {
        if ($organizationId < 1 || $decisionId < 1 || $decisionVersion < 1 || $supplierRequestId < 1) {
            throw new DomainException('Supplier award decision identity is invalid.');
        }
        if (! $isLowestPriceSelected && trim((string) $decisionReason) === '') {
            throw new DomainException('Non-lowest supplier award requires an immutable reason.');
        }
        $invitedSupplierIds = $this->positiveIds($invitedSupplierIds, 'invited supplier');
        $comparableProposalVersionIds = $this->positiveIds(
            $comparableProposalVersionIds,
            'comparable proposal version',
        );
        foreach ([$selectedProposalVersionId, $cheapestProposalVersionId, $medianProposalVersionId] as $versionId) {
            if (! in_array($versionId, $comparableProposalVersionIds, true)) {
                throw new DomainException('Award proposal version must belong to the pinned comparable set.');
            }
        }
        if ($isLowestPriceSelected !== ($selectedProposalVersionId === $cheapestProposalVersionId)) {
            throw new DomainException('Award lowest-price flag does not match pinned proposal versions.');
        }
        if (! hash_equals(hash('sha256', implode(',', $comparableProposalVersionIds)), $comparableSetHash)) {
            throw new DomainException('Award comparable proposal set hash is invalid.');
        }
        $selectedVersion = SupplierProposalVersion::query()
            ->whereKey($selectedProposalVersionId)
            ->where('organization_id', $organizationId)
            ->first();
        if (! $selectedVersion instanceof SupplierProposalVersion
            || $selectedVersion->supplier_party_id === null
            || (int) $selectedVersion->supplier_request_id !== $supplierRequestId
            || ! is_array($selectedVersion->dimension_snapshot)
            || ! is_string($selectedVersion->dimension_hash)
            || ! hash_equals(
                hash('sha256', CanonicalJson::encode($selectedVersion->dimension_snapshot)),
                $selectedVersion->dimension_hash,
            )) {
            throw new DomainException('Selected proposal version lacks immutable award dimensions.');
        }
        $proposalDimensions = $selectedVersion->dimension_snapshot;
        $dimensionSnapshot = [
            'project_id' => $projectId,
            'supplier_party_id' => (int) $selectedVersion->supplier_party_id,
            'currency' => $proposalDimensions['currency'] ?? null,
            'lines' => $proposalDimensions['lines'] ?? [],
            'procurement_method' => count($invitedSupplierIds) > 1 ? 'competitive' : 'single_source',
        ];
        $dimensionHash = hash('sha256', CanonicalJson::encode($dimensionSnapshot));

        $attributes = [
            'organization_id' => $organizationId,
            'decision_id' => $decisionId,
            'decision_version' => $decisionVersion,
            'purchase_request_id' => $purchaseRequestId,
            'project_id' => $projectId,
            'selected_supplier_party_id' => (int) $selectedVersion->supplier_party_id,
            'dimension_snapshot' => $dimensionSnapshot,
            'dimension_hash' => $dimensionHash,
            'supplier_request_id' => $supplierRequestId,
            'selected_proposal_version_id' => $selectedProposalVersionId,
            'cheapest_proposal_version_id' => $cheapestProposalVersionId,
            'median_proposal_version_id' => $medianProposalVersionId,
            'invited_supplier_ids' => $invitedSupplierIds,
            'comparable_proposal_version_ids' => $comparableProposalVersionIds,
            'excluded_comparisons' => $excludedComparisons,
            'comparable_set_hash' => $comparableSetHash,
            'is_lowest_price_selected' => $isLowestPriceSelected,
            'decision_reason' => $decisionReason,
            'selected_by' => $selectedBy,
            'selected_at' => $selectedAt,
        ];
        $canonical = $attributes;
        $canonical['selected_at'] = $selectedAt->format(DATE_ATOM);
        ksort($canonical, SORT_STRING);
        $attributes['source_hash'] = hash('sha256', CanonicalJson::encode($canonical));

        return DB::transaction(function () use (
            $attributes,
            $decisionId,
            $decisionVersion,
            $organizationId,
        ): SupplierAwardDecisionVersion {
            if (DB::getDriverName() === 'pgsql') {
                DB::select(
                    'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                    ['supplier-award:'.$organizationId.':'.$decisionId],
                );
            }
            $existing = SupplierAwardDecisionVersion::query()
                ->where('organization_id', $organizationId)
                ->where('decision_id', $decisionId)
                ->where('decision_version', $decisionVersion)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof SupplierAwardDecisionVersion) {
                if (! hash_equals((string) $existing->source_hash, $attributes['source_hash'])) {
                    throw new DomainException('Supplier award decision idempotency conflict.');
                }

                return $existing;
            }
            $latest = SupplierAwardDecisionVersion::query()
                ->where('organization_id', $organizationId)
                ->where('decision_id', $decisionId)
                ->orderByDesc('decision_version')
                ->lockForUpdate()
                ->first();
            $expectedVersion = $latest instanceof SupplierAwardDecisionVersion
                ? ((int) $latest->getAttribute('decision_version')) + 1
                : 1;
            if ($decisionVersion !== $expectedVersion) {
                throw new DomainException('Supplier award decision version must be monotonic.');
            }

            return SupplierAwardDecisionVersion::query()->create($attributes);
        }, 3);
    }

    private function positiveIds(array $ids, string $label): array
    {
        if (! array_is_list($ids) || $ids === []) {
            throw new DomainException("Award {$label} identities are invalid.");
        }
        $normalized = [];
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw new DomainException("Award {$label} identities are invalid.");
            }
            $normalized[$id] = $id;
        }
        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
