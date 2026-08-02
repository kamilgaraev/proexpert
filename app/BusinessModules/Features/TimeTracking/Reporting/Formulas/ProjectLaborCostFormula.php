<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting\Formulas;

use App\BusinessModules\Features\TimeTracking\Reporting\DTO\EffectiveLaborRateResolution;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\ProjectLaborCostMetrics;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\ProjectLaborEntryFact;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class ProjectLaborCostFormula
{
    public function calculate(
        ProjectLaborEntryFact $entry,
        ?EffectiveLaborRateResolution $rate,
        ?string $plannedHours,
    ): ?ProjectLaborCostMetrics {
        if ($entry->status !== 'approved') {
            return null;
        }

        $hours = BigDecimal::of($entry->hours);
        $warnings = [];
        $amount = null;
        $cost = null;
        $currency = null;
        if ($rate === null) {
            $warnings[] = 'MISSING_EFFECTIVE_RATE';
        } elseif ($rate->currency === null) {
            $amount = $rate->amount;
            $warnings[] = 'MISSING_RATE_CURRENCY';
        } else {
            $amount = $rate->amount;
            $currency = $rate->currency;
            $cost = $hours->multipliedBy($rate->amount);
        }

        $accepted = $entry->acceptedUnits === null ? null : BigDecimal::of($entry->acceptedUnits);
        $costPerUnit = $cost !== null && $accepted !== null && !$accepted->isZero()
            ? $cost->dividedBy($accepted, 8, RoundingMode::HalfUp)
            : null;
        $variance = $plannedHours === null ? null : $hours->minus($plannedHours);
        if ($plannedHours === null) {
            $warnings[] = 'MISSING_PLANNED_HOURS';
        }

        return new ProjectLaborCostMetrics(
            approvedHours: self::decimal($hours),
            billableHours: self::decimal($entry->billable ? $hours : BigDecimal::zero()),
            billablePercent: $entry->billable ? '100.00' : '0.00',
            rate: $amount === null ? null : self::decimal(BigDecimal::of($amount)),
            cost: $cost === null ? null : self::decimal($cost),
            currency: $currency,
            hoursVariance: $variance === null ? null : self::decimal($variance),
            costPerAcceptedUnit: $costPerUnit === null ? null : self::decimal($costPerUnit),
            qualityWarnings: $warnings,
        );
    }

    private static function decimal(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }
}
