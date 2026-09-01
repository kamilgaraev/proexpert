<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;

final class WarehouseLogisticUnitRelationService
{
    public function assertValid(
        int $organizationId,
        int $warehouseId,
        array $validated,
        ?WarehouseLogisticUnit $unit = null
    ): void {
        $zoneId = array_key_exists('zone_id', $validated)
            ? ($validated['zone_id'] !== null ? (int) $validated['zone_id'] : null)
            : $unit?->zone_id;
        $cellId = array_key_exists('cell_id', $validated)
            ? ($validated['cell_id'] !== null ? (int) $validated['cell_id'] : null)
            : $unit?->cell_id;
        $parentUnitId = array_key_exists('parent_unit_id', $validated)
            ? ($validated['parent_unit_id'] !== null ? (int) $validated['parent_unit_id'] : null)
            : $unit?->parent_unit_id;

        $this->assertZone($warehouseId, $zoneId);
        $this->assertCell($organizationId, $warehouseId, $zoneId, $cellId);
        $this->assertParent($organizationId, $warehouseId, $parentUnitId, $unit);
    }

    private function assertZone(int $warehouseId, ?int $zoneId): void
    {
        if ($zoneId === null) {
            return;
        }

        $exists = WarehouseZone::query()
            ->where('warehouse_id', $warehouseId)
            ->where('id', $zoneId)
            ->exists();

        if (! $exists) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.zone_invalid'));
        }
    }

    private function assertCell(
        int $organizationId,
        int $warehouseId,
        ?int $zoneId,
        ?int $cellId
    ): void {
        if ($cellId === null) {
            return;
        }

        $cell = WarehouseStorageCell::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->find($cellId);

        if (! $cell) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.cell_invalid'));
        }

        if ($zoneId !== null && $cell->zone_id !== null && $cell->zone_id !== $zoneId) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.cell_zone_mismatch'));
        }
    }

    private function assertParent(
        int $organizationId,
        int $warehouseId,
        ?int $parentUnitId,
        ?WarehouseLogisticUnit $unit
    ): void {
        if ($parentUnitId === null) {
            return;
        }

        $parentUnit = WarehouseLogisticUnit::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->find($parentUnitId);

        if (! $parentUnit) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.parent_invalid'));
        }

        if ($unit && $parentUnit->id === $unit->id) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.parent_self'));
        }

        if ($unit && $this->parentChainContains($organizationId, $warehouseId, $parentUnit, $unit->id)) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.parent_descendant'));
        }
    }

    private function parentChainContains(
        int $organizationId,
        int $warehouseId,
        WarehouseLogisticUnit $candidate,
        int $unitId
    ): bool {
        $visited = [];
        $current = $candidate;

        while ($current->parent_unit_id !== null) {
            if (isset($visited[$current->id])) {
                return true;
            }

            $visited[$current->id] = true;
            $parentId = (int) $current->parent_unit_id;

            if ($parentId === $unitId) {
                return true;
            }

            $current = WarehouseLogisticUnit::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->find($parentId);

            if (! $current) {
                return false;
            }
        }

        return false;
    }
}
