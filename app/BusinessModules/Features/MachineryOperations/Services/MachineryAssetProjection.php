<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;

final readonly class MachineryAssetProjection
{
    public function __construct(private MachineryWorkflowPolicy $workflow) {}

    /** @return array<string, mixed> */
    public function project(MachineryAsset $legacy): array
    {
        $canonical = $legacy->organizationAsset;
        $profile = $canonical?->operationProfile;

        return [
            'id' => (int) $legacy->id,
            'organization_asset_id' => $canonical?->id,
            'organization_id' => (int) $legacy->organization_id,
            'machinery_id' => $canonical?->machinery_id ?? $legacy->machinery_id,
            'current_project_id' => $canonical?->current_project_id ?? $legacy->current_project_id,
            'current_schedule_task_id' => $legacy->current_schedule_task_id,
            'asset_code' => $legacy->asset_code,
            'name' => $canonical?->name ?? $legacy->name,
            'inventory_number' => $canonical?->inventory_number ?? $legacy->inventory_number,
            'serial_number' => $canonical?->serial_number,
            'qr_code' => $canonical?->qr_code,
            'ownership_type' => $canonical?->ownership_type ?? $legacy->ownership_type,
            'status' => $this->workflow->status($legacy),
            'lifecycle_status' => $canonical?->lifecycle_status?->value,
            'technical_status' => $canonical?->technical_status?->value,
            'current_warehouse_id' => $canonical?->current_warehouse_id,
            'responsible_user_id' => $canonical?->responsible_user_id,
            'operating_cost_per_hour' => $profile !== null ? $profile->operating_cost_per_hour : $legacy->operating_cost_per_hour,
            'fuel_type' => $profile !== null ? $profile->fuel_type : $legacy->fuel_type,
            'fuel_consumption_rate' => $profile !== null ? $profile->fuel_consumption_rate : $legacy->fuel_consumption_rate,
            'meter_hours' => $profile !== null ? $profile->meter_value : $legacy->meter_hours,
            'metadata' => $canonical?->metadata ?? $legacy->metadata,
        ];
    }
}
