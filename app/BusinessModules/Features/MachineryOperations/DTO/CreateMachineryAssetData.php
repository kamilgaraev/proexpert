<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\DTO;

use App\BusinessModules\Features\MachineryOperations\Enums\MachineryAssetType;

final readonly class CreateMachineryAssetData
{
    public function __construct(
        public MachineryAssetType $assetType,
        public string $name,
        public string $inventoryNumber,
        public ?string $serialNumber,
        public string $ownershipType,
        public bool $tracksMeter,
        public bool $tracksFuel,
        public bool $tracksProduction,
        public bool $maintenanceEnabled,
        public ?string $meterUnit,
        public float $operatingCostPerHour,
        public ?string $fuelType,
        public ?float $fuelConsumptionRate,
        public float $meterValue,
    ) {}
}
