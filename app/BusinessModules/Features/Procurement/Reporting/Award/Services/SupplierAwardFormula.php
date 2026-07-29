<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ComparableProposalVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\SupplierAwardMetric;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

final readonly class SupplierAwardFormula
{
    public function __construct(
        private SupplierProposalComparabilityPolicy $comparability = new SupplierProposalComparabilityPolicy,
    ) {}

    /**
     * @param  list<int>  $invitedSupplierIds
     * @param  list<ComparableProposalVersion>  $comparableProposalVersions
     */
    public function calculate(
        array $invitedSupplierIds,
        array $comparableProposalVersions,
        int $selectedProposalVersionId,
    ): SupplierAwardMetric {
        $proposals = $this->comparability->comparable($comparableProposalVersions, $selectedProposalVersionId);
        if ($invitedSupplierIds === [] || $proposals === []) {
            throw new DomainException('Award metric requires invited suppliers and a comparable selected proposal.');
        }

        $selected = null;
        $amounts = [];
        $seenProposalIds = [];
        $seenSupplierIds = [];
        $invited = array_fill_keys(array_unique($invitedSupplierIds), true);
        foreach ($proposals as $proposal) {
            if (isset($seenProposalIds[$proposal->proposalVersionId])
                || isset($seenSupplierIds[$proposal->supplierId])
                || ! isset($invited[$proposal->supplierId])) {
                throw new DomainException('Award comparable set must contain one invited proposal per supplier.');
            }
            $seenProposalIds[$proposal->proposalVersionId] = true;
            $seenSupplierIds[$proposal->supplierId] = true;
            $amounts[] = $proposal->amountMinor;
            if ($proposal->proposalVersionId === $selectedProposalVersionId) {
                $selected = $proposal;
            }
        }
        if (! $selected instanceof ComparableProposalVersion) {
            throw new DomainException('Selected proposal is absent from comparable set.');
        }

        sort($amounts, SORT_NUMERIC);
        $count = count($amounts);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $amounts[$middle]
            : intdiv($amounts[$middle - 1] + $amounts[$middle], 2);
        $cheapest = $amounts[0];
        $premium = $selected->amountMinor - $cheapest;
        $medianVariance = $selected->amountMinor - $median;

        $ids = array_map(
            static fn (ComparableProposalVersion $proposal): int => $proposal->proposalVersionId,
            $proposals,
        );
        sort($ids, SORT_NUMERIC);

        return new SupplierAwardMetric(
            invitedCount: count(array_unique($invitedSupplierIds)),
            respondedCount: count(array_unique(array_column($proposals, 'supplierId'))),
            selectedAmountMinor: $selected->amountMinor,
            cheapestAmountMinor: $cheapest,
            medianAmountMinor: $median,
            premiumMinor: $premium,
            premiumRatio: self::ratio($premium, $cheapest),
            medianVarianceMinor: $medianVariance,
            medianVarianceRatio: self::ratio($medianVariance, $median),
            participationRatio: self::ratio(count(array_unique(array_column($proposals, 'supplierId'))), count(array_unique($invitedSupplierIds))),
            comparableSetHash: hash('sha256', implode(',', $ids)),
        );
    }

    private static function ratio(int $numerator, int $denominator): string
    {
        if ($denominator === 0) {
            throw new DomainException('Ratio denominator cannot be zero.');
        }

        return (string) BigDecimal::of($numerator)->dividedBy(
            BigDecimal::of($denominator),
            8,
            RoundingMode::HalfUp,
        );
    }
}
