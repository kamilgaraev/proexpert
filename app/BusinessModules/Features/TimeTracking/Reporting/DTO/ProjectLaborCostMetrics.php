<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting\DTO;

final readonly class ProjectLaborCostMetrics
{
    public function __construct(
        public string $approvedHours,
        public string $billableHours,
        public string $billablePercent,
        public ?string $rate,
        public ?string $cost,
        public ?string $currency,
        public ?string $hoursVariance,
        public ?string $costPerAcceptedUnit,
        public array $qualityWarnings,
    ) {
    }
}
