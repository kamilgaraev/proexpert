<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO;

final readonly class InventoryBalanceFact
{
    public function __construct(
        public string $onHandQuantity,
        public string $reservedQuantity,
        public string $unitDimension,
        public string $unitCode,
        public string $conversionVersion,
        public ?int $unitPriceMinor = null,
        public ?string $currency = null,
        public ?string $currencySource = null,
    ) {}
}
