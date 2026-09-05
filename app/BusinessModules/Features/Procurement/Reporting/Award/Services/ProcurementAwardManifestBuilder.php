<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardCandidateEvidence;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardManifest;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardCompleteness;
use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;
use DomainException;
use InvalidArgumentException;

final class ProcurementAwardManifestBuilder
{
    public const CANDIDATE_LIMIT = 100;

    public const CAPTURE_SLO_MILLISECONDS = 250;

    public function build(array $rows, int $selectedProposalId): ProcurementAwardManifest
    {
        if ($rows === []) {
            throw new DomainException('procurement_award_candidates_required');
        }

        if (count($rows) > self::CANDIDATE_LIMIT) {
            throw new DomainException('procurement_award_candidate_limit_exceeded');
        }

        usort($rows, static fn (array $left, array $right): int => [
            (int) ($left['proposal_id'] ?? 0),
            (int) ($left['proposal_version_id'] ?? 0),
        ] <=> [
            (int) ($right['proposal_id'] ?? 0),
            (int) ($right['proposal_version_id'] ?? 0),
        ]);

        $purchaseRequestIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['purchase_request_id'] ?? 0),
            $rows,
        )));
        if (count($purchaseRequestIds) !== 1 || $purchaseRequestIds[0] < 1) {
            throw new DomainException('procurement_award_purchase_request_round_not_supported');
        }

        $currencies = [];
        foreach ($rows as $row) {
            if ($this->availabilityExclusions($row) !== []) {
                continue;
            }
            $snapshot = is_array($row['commercial_snapshot'] ?? null) ? $row['commercial_snapshot'] : [];
            $currency = strtoupper(trim((string) ($snapshot['currency'] ?? '')));
            if ($currency !== '') {
                $currencies[] = $currency;
            }
        }
        $currencies = array_values(array_unique($currencies));

        $candidates = [];
        $scopeRows = [];
        foreach ($rows as $row) {
            $candidate = $this->candidate(
                $row,
                count($currencies) === 1,
                true,
            );
            $candidates[] = $candidate;
            if ($candidate->comparable) {
                $scopeRows[] = $row;
            }
        }
        if (! (new ProcurementAwardRequestScopeComparator)->equivalent($scopeRows)) {
            foreach ($candidates as $index => $candidate) {
                if ($candidate->comparable) {
                    $candidates[$index] = $this->candidate($rows[$index], true, false);
                }
            }
        }

        $selected = null;
        foreach ($candidates as $candidate) {
            if ($candidate->proposalId === $selectedProposalId) {
                $selected = $candidate;
                break;
            }
        }
        if (! $selected instanceof ProcurementAwardCandidateEvidence) {
            throw new DomainException('procurement_award_selected_candidate_missing');
        }

        $availableCandidates = array_filter(
            $candidates,
            fn (int $index): bool => $this->availabilityExclusions($rows[$index]) === [],
            ARRAY_FILTER_USE_KEY,
        );
        $legacy = array_filter(
            $availableCandidates,
            static fn (ProcurementAwardCandidateEvidence $candidate): bool => in_array(
                'legacy_unverified_proposal_version',
                $candidate->exclusionCodes,
                true,
            ) || in_array('legacy_unverified_request_version', $candidate->exclusionCodes, true),
        );
        $comparable = array_values(array_filter(
            $candidates,
            static fn (ProcurementAwardCandidateEvidence $candidate): bool => $candidate->comparable,
        ));

        $complete = count($comparable) === count($candidates) && $comparable !== [];
        $hasGap = array_filter(
            $availableCandidates,
            static fn (ProcurementAwardCandidateEvidence $candidate): bool => array_filter(
                $candidate->exclusionCodes,
                static fn (string $code): bool => str_starts_with($code, 'missing_'),
            ) !== [],
        ) !== [];
        $rankableSubset = $selected->comparable && $comparable !== [] && $legacy === [] && ! $hasGap;
        $completeness = $complete
            ? ProcurementAwardCompleteness::COMPLETE
            : ($rankableSubset
                ? ProcurementAwardCompleteness::COMPARABLE_SUBSET
                : ($legacy !== []
                ? ProcurementAwardCompleteness::LEGACY_UNVERIFIED
                : ($hasGap ? ProcurementAwardCompleteness::GAP : ProcurementAwardCompleteness::NOT_COMPARABLE)));

        $ranked = [];
        if ($complete || $rankableSubset) {
            $ranked = $comparable;
            usort($ranked, static function (
                ProcurementAwardCandidateEvidence $left,
                ProcurementAwardCandidateEvidence $right,
            ): int {
                $amountOrder = ProcurementAwardCanonicalizer::compare(
                    (string) $left->comparisonTotal,
                    (string) $right->comparisonTotal,
                );

                return $amountOrder !== 0 ? $amountOrder : $left->proposalId <=> $right->proposalId;
            });
        }

        $cheapest = $ranked[0] ?? null;
        $selectedRank = null;
        foreach ($ranked as $index => $candidate) {
            if ($candidate->proposalId === $selectedProposalId) {
                $selectedRank = $index + 1;
                break;
            }
        }
        $quarantineCodes = array_values(array_unique(array_merge(...array_map(
            static fn (ProcurementAwardCandidateEvidence $candidate): array => $candidate->exclusionCodes,
            $candidates,
        ))));
        sort($quarantineCodes, SORT_STRING);

        return new ProcurementAwardManifest(
            candidates: $candidates,
            completeness: $completeness,
            selectedProposalId: $selected->proposalId,
            selectedProposalVersionId: $selected->proposalVersionId,
            cheapestProposalId: $cheapest?->proposalId,
            cheapestProposalVersionId: $cheapest?->proposalVersionId,
            selectedRank: $selectedRank,
            cheapestRank: $cheapest === null ? null : 1,
            quarantineCodes: $quarantineCodes,
        );
    }

    private function candidate(
        array $row,
        bool $sameCurrency,
        bool $sameRequestVersion,
    ): ProcurementAwardCandidateEvidence {
        $snapshot = is_array($row['commercial_snapshot'] ?? null) ? $row['commercial_snapshot'] : [];
        $exclusions = [];

        if (($row['project_id'] ?? null) === null) {
            $exclusions[] = 'missing_project_lineage';
        }

        $versionHash = $this->hashOrNull($row['version_content_hash'] ?? null);
        $requestVersionHash = $this->hashOrNull($row['supplier_request_version_hash'] ?? null);
        $proposalVersionId = $this->nullablePositive($row['proposal_version_id'] ?? null);
        $requestVersionId = $this->nullablePositive($row['supplier_request_version_id'] ?? null);
        if ($proposalVersionId === null) {
            $exclusions[] = 'missing_proposal_version';
        }
        if ($requestVersionId === null) {
            $exclusions[] = 'missing_request_version';
        }
        if ($versionHash === null) {
            $exclusions[] = 'legacy_unverified_proposal_version';
        }
        if ($requestVersionHash === null) {
            $exclusions[] = 'legacy_unverified_request_version';
        }
        if (! $sameRequestVersion) {
            $exclusions[] = 'request_version_mismatch';
        }

        $proposalStatus = trim((string) ($row['proposal_status'] ?? '')) ?: null;
        $proposalValidUntil = $this->dateOrNull($row['proposal_valid_until'] ?? null);
        $exclusions = array_merge($exclusions, $this->availabilityExclusions($row));

        $currency = strtoupper(trim((string) ($snapshot['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            $currency = null;
            $exclusions[] = 'missing_or_invalid_currency';
        } elseif (! $sameCurrency) {
            $exclusions[] = 'currency_mismatch';
        }
        $vatMode = trim((string) ($snapshot['vat_mode'] ?? '')) ?: null;
        $vatRate = $this->decimalOrNull($snapshot['vat_rate'] ?? null, $exclusions, 'missing_vat_rate');
        if ($vatMode === null) {
            $exclusions[] = 'missing_vat_mode';
        }

        $subtotal = $this->decimalOrNull($snapshot['subtotal_amount'] ?? null, $exclusions, 'missing_subtotal_amount');
        $delivery = $this->decimalOrNull($snapshot['delivery_amount'] ?? null, $exclusions, 'missing_delivery_amount');
        $vat = $this->decimalOrNull($snapshot['vat_amount'] ?? null, $exclusions, 'missing_vat_amount');
        $total = $this->decimalOrNull($snapshot['total_amount'] ?? null, $exclusions, 'missing_total_amount');
        $comparisonTotal = null;
        if ($subtotal !== null && $delivery !== null && $vat !== null && $total !== null) {
            $comparisonTotal = ProcurementAwardCanonicalizer::compare($total, '0') > 0
                ? $total
                : ProcurementAwardCanonicalizer::add($subtotal, $delivery, $vat);
            if (ProcurementAwardCanonicalizer::compare($comparisonTotal, '0') <= 0) {
                $exclusions[] = 'non_positive_comparison_total';
                $comparisonTotal = null;
            }
        }

        $coverage = $this->coverage($row, $exclusions);
        $deliveryDueDate = $this->dateOrNull($snapshot['delivery_due_date'] ?? null);
        $leadTimeDays = filter_var($snapshot['lead_time_days'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        if ($deliveryDueDate === null && ($leadTimeDays === null || $leadTimeDays < 0)) {
            $leadTimeDays = null;
        }

        $exclusions = array_values(array_unique($exclusions));
        sort($exclusions, SORT_STRING);

        return new ProcurementAwardCandidateEvidence(
            organizationId: $this->positive($row['organization_id'] ?? null),
            projectId: $this->nullablePositive($row['project_id'] ?? null),
            purchaseRequestId: $this->positive($row['purchase_request_id'] ?? null),
            supplierRequestId: $this->positive($row['supplier_request_id'] ?? null),
            supplierRequestVersionId: $requestVersionId,
            supplierRequestVersionHash: $requestVersionHash,
            proposalId: $this->positive($row['proposal_id'] ?? null),
            proposalVersionId: $proposalVersionId,
            supplierPartyId: $this->positive($row['supplier_party_id'] ?? null),
            proposalStatus: $proposalStatus,
            proposalValidUntil: $proposalValidUntil,
            versionContentHash: $versionHash,
            subtotalAmount: $subtotal,
            deliveryAmount: $delivery,
            vatAmount: $vat,
            totalAmount: $total,
            comparisonTotal: $comparisonTotal,
            currency: $currency,
            vatMode: $vatMode,
            vatRate: $vatRate,
            deliveryDueDate: $deliveryDueDate,
            leadTimeDays: $leadTimeDays,
            requestLineCoverage: $coverage,
            comparable: $exclusions === [] && $comparisonTotal !== null,
            exclusionCodes: $exclusions,
        );
    }

    private function availabilityExclusions(array $row): array
    {
        $exclusions = [];
        $proposalStatus = trim((string) ($row['proposal_status'] ?? ''));
        if (! in_array($proposalStatus, [
            SupplierProposalStatusEnum::SUBMITTED->value,
            SupplierProposalStatusEnum::ACCEPTED->value,
        ], true)) {
            $exclusions[] = 'proposal_status_not_comparable';
        }
        $validUntil = $this->dateOrNull($row['proposal_valid_until'] ?? null);
        $selectionDate = $this->dateOrNull($row['selection_date'] ?? null);
        if (($row['proposal_valid_until'] ?? null) !== null && $validUntil === null) {
            $exclusions[] = 'invalid_proposal_validity';
        } elseif ($validUntil !== null && $selectionDate !== null && $validUntil < $selectionDate) {
            $exclusions[] = 'expired_proposal';
        }

        return $exclusions;
    }

    private function coverage(array $row, array &$exclusions): array
    {
        $requestLines = is_array($row['request_lines'] ?? null) ? $row['request_lines'] : [];
        $proposalLines = is_array($row['commercial_snapshot']['lines'] ?? null)
            ? $row['commercial_snapshot']['lines']
            : [];
        $coverage = new \App\BusinessModules\Features\Procurement\Services\SupplierProposalLineCoverageService;
        $result = $coverage->evaluate($requestLines, $proposalLines);
        $exclusions = array_merge($exclusions, $result['issues']);
        if (is_array($row['purchase_request_lines'] ?? null)) {
            $purchaseCoverage = $coverage->evaluatePurchase(
                $row['purchase_request_lines'],
                is_array($row['comparison_request_lines'] ?? null) ? $row['comparison_request_lines'] : [],
                $proposalLines,
            );
            if (! $purchaseCoverage['complete']) {
                $exclusions[] = 'incomplete_purchase_line_coverage';
            }
        }

        return $result['lines'];
    }

    private function decimalOrNull(mixed $value, array &$exclusions, string $code): ?string
    {
        try {
            return ProcurementAwardCanonicalizer::decimal($value);
        } catch (InvalidArgumentException) {
            $exclusions[] = $code;

            return null;
        }
    }

    private function hashOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private function positive(mixed $value): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('procurement_award_lineage_invalid');
        }

        return $value;
    }

    private function nullablePositive(mixed $value): ?int
    {
        return $value === null ? null : $this->positive($value);
    }
}
