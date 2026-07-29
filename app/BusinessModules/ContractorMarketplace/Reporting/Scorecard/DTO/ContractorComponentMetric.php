<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO;

final readonly class ContractorComponentMetric
{
    public function __construct(
        public string $componentCode,
        public string $unitCode,
        public ?string $mean,
        public int $sampleSize,
        public int $eligibleCount,
        public ?string $coverage,
    ) {
    }
}
