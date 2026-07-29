<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

final readonly class ProcurementCycleMetric
{
    /** @param array<string,int> $stageDurationSeconds */
    public function __construct(
        public array $stageDurationSeconds,
        public int $totalDurationSeconds,
        public int $slaNumerator,
        public int $slaDenominator,
        public bool $closed,
    ) {}
}
