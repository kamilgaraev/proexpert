<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas;

use Brick\Math\BigDecimal;

final class AttendanceDurationEvidence
{
    /** Presence confirms attendance; only an explicit quantified correction confirms worked duration. */
    public static function resolve(?string $correctionStatus, mixed $correctedHours, bool $hasScan, bool $hasApprovedAbsence): array
    {
        if ($correctionStatus === 'at_work' && $correctedHours !== null) {
            $hours = BigDecimal::of((string) $correctedHours);
            if (! $hours->isLessThan(BigDecimal::zero())) {
                return ['known' => true, 'confirmed_hours' => (string) $hours, 'presence_confirmed' => true];
            }
        } elseif ($correctionStatus !== null && $correctionStatus !== 'at_work') {
            return ['known' => true, 'confirmed_hours' => '0', 'presence_confirmed' => false];
        } elseif ($hasApprovedAbsence && ! $hasScan) {
            return ['known' => true, 'confirmed_hours' => '0', 'presence_confirmed' => false];
        }

        return ['known' => false, 'confirmed_hours' => null, 'presence_confirmed' => $hasScan || $correctionStatus === 'at_work'];
    }
}
