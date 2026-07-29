<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas;

use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\PayrollReadinessMetrics;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class PayrollReadinessFormula
{
    /** @param array<string, string|int> $sourceAmounts */
    public function calculate(
        int $sourceRowCount,
        int $coveredSourceRowCount,
        int $blockingIssueCount,
        int $warningCount,
        string $sourceHours,
        array $sourceAmounts,
        string $unassignedHours,
        string $unratedHours,
    ): PayrollReadinessMetrics {
        if (min($sourceRowCount, $coveredSourceRowCount, $blockingIssueCount, $warningCount) < 0
            || $coveredSourceRowCount > $sourceRowCount) {
            throw new \InvalidArgumentException('payroll_readiness_counts_invalid');
        }

        $coverage = $sourceRowCount === 0
            ? null
            : BigDecimal::of($coveredSourceRowCount)
                ->multipliedBy(100)
                ->dividedBy($sourceRowCount, 8, RoundingMode::HalfUp);
        $issueRate = $sourceRowCount === 0
            ? null
            : BigDecimal::of($blockingIssueCount + $warningCount)
                ->multipliedBy(100)
                ->dividedBy($sourceRowCount, 8, RoundingMode::HalfUp);
        $complete = $coverage !== null && $coverage->isEqualTo(BigDecimal::of(100));
        $ready = $sourceRowCount > 0 && $blockingIssueCount === 0 && $complete;
        $state = $blockingIssueCount > 0
            ? 'blocked'
            : ($sourceRowCount === 0 ? 'unavailable' : ($complete ? 'ready' : 'incomplete'));
        $normalizedSourceAmounts = [];
        foreach ($sourceAmounts as $currency => $amount) {
            if (!is_string($currency)
                || (preg_match('/^[A-Z]{3}$/', $currency) !== 1 && $currency !== 'UNSPECIFIED')
                || (!is_string($amount) && !is_int($amount))) {
                throw new \InvalidArgumentException('payroll_readiness_source_amounts_invalid');
            }
            $normalizedSourceAmounts[$currency] = self::decimal($amount);
        }
        ksort($normalizedSourceAmounts, SORT_STRING);

        return new PayrollReadinessMetrics(
            sourceRowCount: $sourceRowCount,
            sourceHours: self::decimal($sourceHours),
            sourceAmounts: $normalizedSourceAmounts,
            coveragePercent: $coverage === null ? null : self::decimal($coverage),
            blockingIssueCount: $blockingIssueCount,
            warningCount: $warningCount,
            issueRate: $issueRate === null ? null : self::decimal($issueRate),
            unassignedHours: self::decimal($unassignedHours),
            unratedHours: self::decimal($unratedHours),
            ready: $ready,
            readinessState: $state,
        );
    }

    private static function decimal(string|BigDecimal $value): string
    {
        return (string) BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);
    }
}
