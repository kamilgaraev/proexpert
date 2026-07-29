<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas;

use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\AttendanceExecutionMetrics;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class AttendanceExecutionFormula
{
    public function calculate(
        string $eligibleHours,
        string $presentHours,
        string $approvedAbsenceHours,
        string $overtimeHours,
        string $lateHours,
        string $earlyHours,
        bool $corrected,
    ): AttendanceExecutionMetrics {
        $zero = BigDecimal::zero();
        $eligible = self::nonNegative($eligibleHours);
        $present = self::nonNegative($presentHours);
        $absence = self::nonNegative($approvedAbsenceHours);
        $overtime = self::nonNegative($overtimeHours);
        $late = self::nonNegative($lateHours);
        $early = self::nonNegative($earlyHours);
        $covered = $present->plus($absence);
        $unexplained = $eligible->minus($covered);
        if ($unexplained->isLessThan($zero)) {
            $unexplained = $zero;
        }

        $execution = $eligible->isZero()
            ? null
            : $covered->multipliedBy(100)->dividedBy($eligible, 8, RoundingMode::HalfUp);
        if ($execution !== null && $execution->isGreaterThan(BigDecimal::of(100))) {
            $execution = BigDecimal::of(100);
        }

        return new AttendanceExecutionMetrics(
            eligibleHours: self::decimal($eligible),
            presentHours: self::decimal($present),
            approvedAbsenceHours: self::decimal($absence),
            unexplainedAbsenceHours: self::decimal($unexplained),
            overtimeHours: self::decimal($overtime),
            lateHours: self::decimal($late),
            earlyHours: self::decimal($early),
            executionPercent: $execution === null ? null : self::decimal($execution),
            correctionRate: $corrected ? '100.00' : '0.00',
            violation: !$unexplained->isZero(),
        );
    }

    private static function nonNegative(string $value): BigDecimal
    {
        $decimal = BigDecimal::of($value);

        return $decimal->isLessThan(BigDecimal::zero()) ? BigDecimal::zero() : $decimal;
    }

    private static function decimal(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }
}
