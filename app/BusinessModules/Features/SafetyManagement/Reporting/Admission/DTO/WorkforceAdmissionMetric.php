<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DTO;

use InvalidArgumentException;

final readonly class WorkforceAdmissionMetric
{
    public function __construct(
        public int $assignmentId,
        public int $personId,
        public int $siteId,
        public string $date,
        public string $status,
        public bool $blocked,
        public array $blockerCodes,
        public array $warningCodes,
        public int $requirementCount,
        public int $personDenominator = 1,
        public int $admittedPeople = 0,
        public int $partialPeople = 0,
        public int $notAdmittedPeople = 0,
    ) {
        if ($assignmentId < 0 || $personId < 0 || $siteId < 0 || trim($date) === ''
            || ! in_array($status, ['admitted', 'partial', 'not_admitted', 'summary'], true)
            || min($requirementCount, $personDenominator, $admittedPeople, $partialPeople, $notAdmittedPeople) < 0) {
            throw new InvalidArgumentException('workforce_admission_metric_invalid');
        }
    }
}
