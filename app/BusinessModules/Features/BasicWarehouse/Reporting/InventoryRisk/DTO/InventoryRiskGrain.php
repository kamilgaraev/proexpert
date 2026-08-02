<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO;

final readonly class InventoryRiskGrain
{
    public function __construct(
        public int $warehouseId,
        public ?int $projectId,
        public int $materialId,
        public string $unitDimension,
        public string $unitCode,
        public string $conversionVersion,
    ) {}

    public function key(): string
    {
        return implode(':', [
            $this->warehouseId,
            $this->projectId ?? 'null',
            $this->materialId,
            $this->unitDimension,
            $this->unitCode,
            $this->conversionVersion,
        ]);
    }
}
