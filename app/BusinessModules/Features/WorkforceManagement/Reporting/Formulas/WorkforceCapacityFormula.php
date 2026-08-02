<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas;

use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\EffectiveAssignmentFact;
use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\WorkforceCapacityMetrics;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class WorkforceCapacityFormula
{
    public function allocatePlannedFte(string $plannedFte, array $assignedFteByProject): array
    {
        $planned = BigDecimal::of($plannedFte)->toScale(2, RoundingMode::HalfUp);
        if ($planned->isLessThan(BigDecimal::zero())) {
            throw new \InvalidArgumentException('workforce_capacity_planned_fte_invalid');
        }

        $assigned = [];
        $totalAssigned = BigDecimal::zero();
        foreach ($assignedFteByProject as $projectKey => $value) {
            if ((!is_string($projectKey) && !is_int($projectKey))
                || (!is_string($value) && !is_int($value))) {
                throw new \InvalidArgumentException('workforce_capacity_allocation_invalid');
            }
            $projectKey = (string) $projectKey;
            $amount = BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);
            if ($amount->isLessThan(BigDecimal::zero())) {
                throw new \InvalidArgumentException('workforce_capacity_allocation_invalid');
            }
            $assigned[$projectKey] = $amount;
            $totalAssigned = $totalAssigned->plus($amount);
        }
        ksort($assigned, SORT_STRING);

        $allocations = [];
        if ($totalAssigned->isLessThanOrEqualTo($planned)) {
            foreach ($assigned as $projectKey => $amount) {
                $allocations[$projectKey] = $amount;
            }
            $residual = $planned->minus($totalAssigned);
            if (!$residual->isZero()) {
                $allocations['none'] = ($allocations['none'] ?? BigDecimal::zero())->plus($residual);
            }
        } elseif (!$planned->isZero()) {
            $remaining = $planned;
            $keys = array_keys($assigned);
            $lastKey = end($keys);
            foreach ($assigned as $projectKey => $amount) {
                $allocation = $projectKey === $lastKey
                    ? $remaining
                    : $planned
                        ->multipliedBy($amount)
                        ->dividedBy($totalAssigned, 2, RoundingMode::Down);
                $allocations[$projectKey] = $allocation;
                $remaining = $remaining->minus($allocation);
            }
        } else {
            foreach ($assigned as $projectKey => $amount) {
                $allocations[$projectKey] = BigDecimal::zero();
            }
        }
        ksort($allocations, SORT_STRING);

        return array_map(
            static fn (BigDecimal $value): string => (string) $value->toScale(2, RoundingMode::HalfUp),
            $allocations,
        );
    }

    public function calculate(
        string $approvedFte,
        array $assignments,
        string $plannedCapacityHours,
        string $rateType,
        ?string $rate,
        ?string $currency,
    ): WorkforceCapacityMetrics {
        $approved = BigDecimal::of($approvedFte);
        $assigned = BigDecimal::zero();
        foreach ($assignments as $assignment) {
            if (!$assignment instanceof EffectiveAssignmentFact) {
                throw new \InvalidArgumentException('workforce_capacity_assignment_invalid');
            }
            $assigned = $assigned->plus($assignment->fte);
        }

        $vacancy = $approved->minus($assigned);
        $overstaffing = $vacancy->isLessThan(BigDecimal::zero()) ? $vacancy->negated() : BigDecimal::zero();
        $vacancy = $vacancy->isLessThan(BigDecimal::zero()) ? BigDecimal::zero() : $vacancy;
        $capacity = BigDecimal::of($plannedCapacityHours);
        $assignedCapacity = $approved->isZero()
            ? BigDecimal::zero()
            : $capacity->multipliedBy($assigned)->dividedBy($approved, 8, RoundingMode::HalfUp);
        $warnings = [];
        $runRate = null;

        if ($rate === null || $currency === null) {
            $warnings[] = 'MISSING_RATE_CURRENCY';
        } else {
            $runRate = $assignedCapacity->multipliedBy($rate);
        }

        return new WorkforceCapacityMetrics(
            approvedFte: self::decimal($approved, 2),
            assignedFte: self::decimal($assigned, 2),
            vacancyFte: self::decimal($vacancy, 2),
            overstaffingFte: self::decimal($overstaffing, 2),
            vacancyPercent: $approved->isZero()
                ? null
                : self::decimal($vacancy->multipliedBy(100)->dividedBy($approved, 8, RoundingMode::HalfUp), 2),
            plannedCapacityHours: self::decimal($capacity, 2),
            assignedCapacityHours: self::decimal($assignedCapacity, 2),
            rateType: $rateType,
            rate: $rate === null ? null : self::decimal(BigDecimal::of($rate), 2),
            currency: $currency,
            periodCostRunRate: $runRate === null ? null : self::decimal($runRate, 2),
            qualityWarnings: $warnings,
        );
    }

    private static function decimal(BigDecimal $value, int $scale): string
    {
        return (string) $value->toScale($scale, RoundingMode::HalfUp);
    }
}
