<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO;

final readonly class InventoryDemandFact
{
    public function __construct(
        public string $approvedQuantity,
        public int $horizonDays,
        public string $unitDimension,
        public string $unitCode,
        public string $conversionVersion,
    ) {}
}
