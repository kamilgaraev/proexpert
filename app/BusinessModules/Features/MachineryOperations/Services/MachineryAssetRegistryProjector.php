<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;

final class MachineryAssetRegistryProjector
{
    public function project(OrganizationAsset $asset): MachineryAsset
    {
        $asset->loadMissing('operationProfile');
        $profile = $asset->operationProfile;
        $existing = MachineryAsset::query()
            ->where('organization_asset_id', $asset->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return MachineryAsset::query()->create([
            'organization_id' => $asset->organization_id,
            'organization_asset_id' => $asset->id,
            'machinery_id' => $asset->machinery_id,
            'current_project_id' => $asset->current_project_id,
            'asset_code' => 'OA-'.$asset->id,
            'name' => $asset->name,
            'inventory_number' => $asset->inventory_number,
            'ownership_type' => $asset->ownership_type,
            'status' => 'available',
            'operating_cost_per_hour' => $profile?->operating_cost_per_hour ?? 0,
            'fuel_type' => $profile?->fuel_type,
            'fuel_consumption_rate' => $profile?->fuel_consumption_rate,
            'meter_hours' => $profile?->meter_value ?? 0,
            'metadata' => [
                'registry_projection' => true,
                'canonical_source' => is_array($asset->metadata)
                    ? ($asset->metadata['canonical_source'] ?? 'warehouse_receipt')
                    : 'warehouse_receipt',
            ],
        ]);
    }
}
