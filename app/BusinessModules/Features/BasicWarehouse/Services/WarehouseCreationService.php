<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Models\Project;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class WarehouseCreationService
{
    public function create(User $actor, array $data): OrganizationWarehouse
    {
        $organizationId = (int) $actor->current_organization_id;
        $warehouseType = $data['warehouse_type'] ?? OrganizationWarehouse::TYPE_CENTRAL;
        $projectId = $warehouseType === OrganizationWarehouse::TYPE_PROJECT ? ($data['project_id'] ?? null) : null;

        if ($warehouseType === OrganizationWarehouse::TYPE_PROJECT) {
            if (!$projectId) {
                throw ValidationException::withMessages([
                    'project_id' => trans_message('warehouse_creation.project_required'),
                ]);
            }

            if (!Project::query()->where('organization_id', $organizationId)->whereKey($projectId)->exists()) {
                throw ValidationException::withMessages([
                    'project_id' => trans_message('warehouse_creation.project_invalid'),
                ]);
            }
        }

        return OrganizationWarehouse::create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'code' => $data['code'],
            'warehouse_type' => $warehouseType,
            'project_id' => $projectId,
            'description' => $data['description'] ?? null,
            'address' => $data['address'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'working_hours' => $data['working_hours'] ?? null,
            'is_main' => $data['is_main'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            'settings' => $data['settings'] ?? [],
            'storage_conditions' => $data['storage_conditions'] ?? [],
        ]);
    }
}
