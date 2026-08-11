<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\DTO;

use App\BusinessModules\Core\AssetManagement\Enums\AssetAccountingMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetOperationalMode;

final readonly class CreateOrganizationAssetData
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $name,
        public string $inventoryNumber,
        public ?string $serialNumber = null,
        public ?string $qrCode = null,
        public AssetAccountingMode $accountingMode = AssetAccountingMode::Serialized,
        public string $ownershipType = 'owned',
        public ?int $materialId = null,
        public ?int $machineryId = null,
        public ?AssetPlacementData $placement = null,
        public ?int $actorId = null,
        public ?array $metadata = null,
        public AssetOperationalMode $operationalMode = AssetOperationalMode::Custody,
        public bool $tracksMeter = false,
        public bool $tracksFuel = false,
        public bool $tracksProduction = false,
        public bool $maintenanceEnabled = false,
        public ?string $meterUnit = null,
        public float $operatingCostPerHour = 0,
        public ?string $fuelType = null,
        public ?float $fuelConsumptionRate = null,
        public float $meterValue = 0,
    ) {}
}
