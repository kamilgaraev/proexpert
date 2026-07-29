<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\DTO;

final readonly class SupplyReliabilitySummary
{
    public function __construct(
        public int $otifNumerator,
        public int $eligibleDenominator,
        public ?string $otifRatio,
    ) {}
}
