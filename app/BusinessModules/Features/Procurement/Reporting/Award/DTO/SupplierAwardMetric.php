<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\DTO;

final readonly class SupplierAwardMetric
{
    public function __construct(
        public int $invitedCount,
        public int $respondedCount,
        public int $selectedAmountMinor,
        public int $cheapestAmountMinor,
        public int $medianAmountMinor,
        public int $premiumMinor,
        public string $premiumRatio,
        public int $medianVarianceMinor,
        public string $medianVarianceRatio,
        public string $participationRatio,
        public string $comparableSetHash,
    ) {}
}
