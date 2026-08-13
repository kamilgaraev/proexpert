<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Core\AssetManagement\DTO\AssetPlacementData;
use App\BusinessModules\Core\AssetManagement\DTO\CreateOrganizationAssetData;
use App\BusinessModules\Core\AssetManagement\Enums\AssetOperationalMode;
use App\BusinessModules\Core\AssetManagement\Services\OrganizationAssetService;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryAssetRegistryProjector;

final class MachineryOperationsAssetFactory
{
    /** @param array<string, mixed> $data */
    public static function create(int $organizationId, array $data): MachineryAsset
    {
        $assetCode = (string) $data['asset_code'];
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $canonical = app(OrganizationAssetService::class)->create(
            $organizationId,
            new CreateOrganizationAssetData(
                name: (string) $data['name'],
                inventoryNumber: (string) ($data['inventory_number'] ?? $assetCode),
                ownershipType: (string) ($data['ownership_type'] ?? 'owned'),
                machineryId: isset($data['machinery_id']) ? (int) $data['machinery_id'] : null,
                placement: isset($data['current_project_id'])
                    ? new AssetPlacementData(projectId: (int) $data['current_project_id'])
                    : null,
                metadata: $metadata,
                operationalMode: AssetOperationalMode::ShiftOperation,
                tracksMeter: true,
                tracksFuel: isset($data['fuel_type']) || isset($data['fuel_consumption_rate']),
                tracksProduction: true,
                maintenanceEnabled: true,
                meterUnit: 'hour',
                operatingCostPerHour: (float) ($data['operating_cost_per_hour'] ?? 0),
                fuelType: isset($data['fuel_type']) ? (string) $data['fuel_type'] : null,
                fuelConsumptionRate: isset($data['fuel_consumption_rate']) ? (float) $data['fuel_consumption_rate'] : null,
                meterValue: (float) ($data['meter_hours'] ?? 0),
            ),
        );

        $legacy = app(MachineryAssetRegistryProjector::class)->project($canonical);
        $status = isset($data['current_project_id']) ? 'assigned' : 'available';
        $legacy->update([
            'current_schedule_task_id' => $data['current_schedule_task_id'] ?? null,
            'asset_code' => $assetCode,
            'status' => $status,
            'metadata' => $metadata,
        ]);
        $canonical->update(['metadata' => [
            ...$metadata,
            'legacy_source' => ['table' => 'machinery_assets', 'id' => (int) $legacy->id],
            'machinery_operation_status' => $status,
        ]]);

        return $legacy->refresh()->load('organizationAsset.operationProfile');
    }
}
