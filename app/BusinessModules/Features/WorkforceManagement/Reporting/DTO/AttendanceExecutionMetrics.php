<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\DTO;

final readonly class AttendanceExecutionMetrics
{
    public function __construct(
        public string $eligibleHours,
        public string $presentHours,
        public string $approvedAbsenceHours,
        public string $unexplainedAbsenceHours,
        public string $overtimeHours,
        public string $lateHours,
        public string $earlyHours,
        public ?string $executionPercent,
        public string $correctionRate,
        public bool $violation,
    ) {
    }
}
