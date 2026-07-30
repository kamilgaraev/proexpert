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
        $requestOwner = DB::table('supplier_requests as award_request')
            ->join('purchase_requests as award_purchase_request', 'award_purchase_request.id', '=', 'award_request.purchase_request_id')
            ->join('site_requests as award_site_request', 'award_site_request.id', '=', 'award_purchase_request.site_request_id')
            ->where('award_request.id', $supplierRequestId)
            ->where('award_request.organization_id', $organizationId)
            ->where('award_purchase_request.organization_id', $organizationId)
            ->first([
                'award_purchase_request.id as purchase_request_id',
                'award_site_request.project_id',
            ]);
        if ($requestOwner === null
            || ($purchaseRequestId !== null && (int) $requestOwner->purchase_request_id !== $purchaseRequestId)
            || ($projectId !== null && (int) $requestOwner->project_id !== $projectId)) {
            throw new DomainException('Supplier award request owner is invalid.');
        }
        $authoritativePurchaseRequestId = (int) $requestOwner->purchase_request_id;
        $authoritativeProjectId = (int) $requestOwner->project_id;
        $versions = SupplierProposalVersion::query()
            ->whereIn('id', $comparableProposalVersionIds)
            ->get()
            ->keyBy('id');
        if ($versions->count() !== count($comparableProposalVersionIds)) {
            throw new DomainException('Award comparable proposal set is incomplete.');
        }
        foreach ($comparableProposalVersionIds as $proposalVersionId) {
            $version = $versions->get($proposalVersionId);
            if (! $version instanceof SupplierProposalVersion
                || (int) $version->organization_id !== $organizationId
                || (int) $version->supplier_request_id !== $supplierRequestId
                || $version->supplier_party_id === null
                || ! is_array($version->dimension_snapshot)
                || ! is_string($version->dimension_hash)
                || ! hash_equals(
                    hash('sha256', CanonicalJson::encode($version->dimension_snapshot)),
                    $version->dimension_hash,
                )
                || (int) ($version->dimension_snapshot['supplier_party_id'] ?? 0) !== (int) $version->supplier_party_id
                || (int) ($version->dimension_snapshot['purchase_request_id'] ?? $authoritativePurchaseRequestId)
                    !== $authoritativePurchaseRequestId
                || (int) ($version->dimension_snapshot['project_id'] ?? $authoritativeProjectId)
                    !== $authoritativeProjectId) {
                throw new DomainException('Comparable proposal version lacks immutable award ownership.');
            }
        }
        $selectedVersion = $versions->get($selectedProposalVersionId);
        if (! $selectedVersion instanceof SupplierProposalVersion) {
            throw new DomainException('Selected proposal version is unavailable.');
        }
        $proposalDimensions = $selectedVersion->dimension_snapshot;
        $dimensionSnapshot = [
            'project_id' => $authoritativeProjectId,
            'purchase_request_id' => $authoritativePurchaseRequestId,
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
            'purchase_request_id' => $authoritativePurchaseRequestId,
            'project_id' => $authoritativeProjectId,
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
