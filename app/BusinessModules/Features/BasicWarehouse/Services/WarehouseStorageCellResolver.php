<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use InvalidArgumentException;

use function trans_message;

final class WarehouseStorageCellResolver
{
    public function resolveForWarehouse(
        int $organizationId,
        int $warehouseId,
        ?int $cellId,
        bool $requireAvailable = true
    ): ?WarehouseStorageCell {
        if ($cellId === null) {
            return null;
        }

        $query = WarehouseStorageCell::query()
            ->with('zone:id,warehouse_id,name,code')
            ->where('id', $cellId)
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->whereNotNull('zone_id')
            ->whereHas('zone', fn ($zoneQuery) => $zoneQuery->where('warehouse_id', $warehouseId));

        if ($requireAvailable) {
            $query
                ->where('is_active', true)
                ->where('status', WarehouseStorageCell::STATUS_AVAILABLE);
        }

        $cell = $query->first();

        if ($cell === null) {
            throw new InvalidArgumentException(trans_message('basic_warehouse.task.cell_invalid'));
        }

        return $cell;
    }

    public function metadata(?WarehouseStorageCell $cell): array
    {
        if ($cell === null) {
            return [];
        }

        return [
            'cell_id' => $cell->id,
            'location_code' => $cell->code,
            'storage_address' => $cell->full_address,
            'zone_id' => $cell->zone_id,
        ];
    }
}
