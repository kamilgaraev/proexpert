<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO;

final readonly class InventoryRiskMetric
{
    /** @param list<string> $qualityWarnings */
    public function __construct(
        public string $availableQuantity,
        public string $consumptionQuantity,
        public ?string $turnover,
        public ?int $onHandValueMinor,
        public ?string $currency,
        public ?string $recommendedOrderQuantity,
        public array $qualityWarnings,
    ) {}
}
