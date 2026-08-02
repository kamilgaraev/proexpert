<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionMetric;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\ProductionAcceptanceFact;

final readonly class AcceptedProductionFormula
{
    private const SCALE = 3;

    public function row(ProductionAcceptanceFact $fact): AcceptedProductionMetric
    {
        $planned = $this->scaled($fact->plannedQuantity);
        $reported = $this->scaled($fact->reportedQuantity);
        $accepted = $this->scaled($fact->acceptedQuantityDelta);

        return new AcceptedProductionMetric(
            plannedQuantity: $this->decimal($planned),
            reportedQuantity: $this->decimal($reported),
            acceptedQuantity: $this->decimal($accepted),
            acceptedPlanVariance: $this->decimal($accepted - $planned),
            reportedAcceptedVariance: $this->decimal($reported - $accepted),
            completionRatio: $planned === 0 ? null : $this->ratio($accepted, $planned),
            acceptedAmountMinor: $fact->approvedRateMinor === null
                ? null
                : $this->roundedDivide($accepted * $fact->approvedRateMinor, 10 ** self::SCALE),
            unitDimension: $fact->unitDimension,
            unitCode: $fact->unitCode,
            conversionVersion: $fact->conversionVersion,
            currency: $fact->currency,
        );
    }

    private function scaled(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $scaled = ((int) $whole * (10 ** self::SCALE))
            + (int) str_pad($fraction, self::SCALE, '0');

        return $negative ? -$scaled : $scaled;
    }

    private function decimal(int $scaled): string
    {
        $negative = $scaled < 0;
        $absolute = abs($scaled);
        $value = intdiv($absolute, 10 ** self::SCALE)
            .'.'
            .str_pad((string) ($absolute % (10 ** self::SCALE)), self::SCALE, '0', STR_PAD_LEFT);

        return $negative ? '-'.$value : $value;
    }

    private function ratio(int $numerator, int $denominator): string
    {
        $negative = ($numerator < 0) !== ($denominator < 0);
        $scaled = intdiv(abs($numerator) * 100_000_000, abs($denominator));
        $value = intdiv($scaled, 100_000_000)
            .'.'
            .str_pad((string) ($scaled % 100_000_000), 8, '0', STR_PAD_LEFT);

        return $negative ? '-'.$value : $value;
    }

    private function roundedDivide(int $numerator, int $denominator): int
    {
        $rounded = intdiv(abs($numerator) + intdiv($denominator, 2), $denominator);

        return $numerator < 0 ? -$rounded : $rounded;
    }
}
