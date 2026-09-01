<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;

final class WarehouseEntitySummaryService
{
    public function __construct(private readonly WarehouseService $warehouseService) {}

    public function summarize(string $entityType, int $entityId, int $organizationId): ?array
    {
        $entity = match ($entityType) {
            'warehouse' => OrganizationWarehouse::query()
                ->with('project:id,name')
                ->where('organization_id', $organizationId)
                ->find($entityId),
            'zone' => WarehouseZone::query()
                ->whereHas('warehouse', fn ($query) => $query->where('organization_id', $organizationId))
                ->find($entityId),
            'cell' => WarehouseStorageCell::query()
                ->where('organization_id', $organizationId)
                ->find($entityId),
            'asset' => Asset::query()
                ->where('organization_id', $organizationId)
                ->find($entityId),
            'inventory_act' => InventoryAct::query()
                ->where('organization_id', $organizationId)
                ->find($entityId),
            'movement' => WarehouseMovement::query()
                ->where('organization_id', $organizationId)
                ->find($entityId),
            'logistic_unit' => WarehouseLogisticUnit::query()
                ->where('organization_id', $organizationId)
                ->find($entityId),
            default => null,
        };

        if ($entity === null) {
            return null;
        }

        if ($entityType === 'warehouse') {
            $entity = $this->warehouseService->withReadableWarehouseName($entity);
        }

        return match ($entityType) {
            'inventory_act' => [
                'id' => $entity->id,
                'name' => $entity->act_number,
                'code' => $entity->act_number,
            ],
            'movement' => [
                'id' => $entity->id,
                'name' => $entity->movement_type,
                'code' => $entity->document_number,
            ],
            default => [
                'id' => $entity->id,
                'name' => $entity->name,
                'code' => $entity->code,
            ],
        };
    }
}
