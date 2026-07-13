<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

final readonly class CostTrendResult
{
    /** @param list<array{bucket: string, total_cost: float, currency: string, sessions: int}> $rows */
    public function __construct(
        public array $rows,
        public bool $truncated,
        public int $omittedCurrencies,
    ) {}
}
