<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\DTO;

final readonly class WorkforceCapacityMetrics
{
    public function __construct(
        public string $approvedFte,
        public string $assignedFte,
        public string $vacancyFte,
        public string $overstaffingFte,
        public ?string $vacancyPercent,
        public string $plannedCapacityHours,
        public string $assignedCapacityHours,
        public string $rateType,
        public ?string $rate,
        public ?string $currency,
        public ?string $periodCostRunRate,
        public array $qualityWarnings,
    ) {
    }
}
