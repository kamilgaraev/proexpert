<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionMetric;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\ProductionAcceptanceFact;

final readonly class AcceptedProductionFormula
{
    public function row(ProductionAcceptanceFact $fact): AcceptedProductionMetric
    {
        $planned = AcceptedProductionQuantity::scaled(
            $fact->plannedQuantity,
            'production_acceptance_quantity_invalid',
        );
        $reported = AcceptedProductionQuantity::scaled(
            $fact->reportedQuantity,
            'production_acceptance_quantity_invalid',
        );
        $accepted = AcceptedProductionQuantity::scaled(
            $fact->acceptedQuantityDelta,
            'production_acceptance_quantity_invalid',
        );

        return new AcceptedProductionMetric(
            plannedQuantity: AcceptedProductionQuantity::decimal($planned),
            reportedQuantity: AcceptedProductionQuantity::decimal($reported),
            acceptedQuantity: AcceptedProductionQuantity::decimal($accepted),
            acceptedPlanVariance: AcceptedProductionQuantity::decimal($accepted - $planned),
            reportedAcceptedVariance: AcceptedProductionQuantity::decimal($reported - $accepted),
            completionRatio: $planned === 0 ? null : $this->ratio($accepted, $planned),
            acceptedAmountMinor: $fact->acceptedAmountMinor
                ?? ($fact->approvedRateMinor === null
                    ? null
                    : AcceptedProductionQuantity::multiplyRateMinor(
                        $accepted,
                        $fact->approvedRateMinor,
                        'production_acceptance_money_invalid',
                    )),
            unitDimension: $fact->unitDimension,
            unitCode: $fact->unitCode,
            conversionVersion: $fact->conversionVersion,
            currency: $fact->currency,
        );
    }

    private function ratio(int $numerator, int $denominator): string
    {
        $negative = ($numerator < 0) !== ($denominator < 0);
        $absoluteNumerator = abs($numerator);
        $absoluteDenominator = abs($denominator);
        $whole = intdiv($absoluteNumerator, $absoluteDenominator);
        $remainder = $absoluteNumerator % $absoluteDenominator;
        $fraction = '';
        for ($position = 0; $position < 8; $position++) {
            $remainder *= 10;
            $fraction .= (string) intdiv($remainder, $absoluteDenominator);
            $remainder %= $absoluteDenominator;
        }
        $value = $whole.'.'.$fraction;

        return $negative ? '-'.$value : $value;
    }

}
