<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DTO;

final readonly class AcceptedProductionMetric
{
    public function __construct(
        public string $plannedQuantity,
        public string $reportedQuantity,
        public string $acceptedQuantity,
        public string $acceptedPlanVariance,
        public string $reportedAcceptedVariance,
        public ?string $completionRatio,
        public ?int $acceptedAmountMinor,
        public string $unitDimension,
        public string $unitCode,
        public string $conversionVersion,
        public ?string $currency,
    ) {
    }
}
