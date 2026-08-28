<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Exceptions\InventoryReservationConflictException;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;

final class InventoryStockService
{
    public function createItems(InventoryAct $act): void
    {
        $groupedBalances = WarehouseBalance::query()
            ->with(['material.measurementUnit', 'cell.zone'])
            ->where('organization_id', $act->organization_id)
            ->where('warehouse_id', $act->warehouse_id)
            ->where(static fn ($query) => $query
                ->where('available_quantity', '>', 0)
                ->orWhere('reserved_quantity', '>', 0))
            ->get()
            ->groupBy(static fn (WarehouseBalance $balance): string => json_encode([
                'material_id' => (int) $balance->material_id,
                'cell_id' => $balance->cell_id !== null ? (int) $balance->cell_id : null,
                'location_code' => $balance->cell_id === null ? $balance->location_code : null,
                'batch_number' => $balance->batch_number,
                'unit_price' => (string) $balance->unit_price,
            ], JSON_THROW_ON_ERROR));

        foreach ($groupedBalances as $balances) {
            $firstBalance = $balances->first();

            InventoryActItem::create([
                'inventory_act_id' => $act->id,
                'material_id' => $firstBalance->material_id,
                'expected_quantity' => $balances->sum(
                    static fn (WarehouseBalance $balance) => $balance->total_quantity
                ),
                'unit_price' => $firstBalance->unit_price,
                'cell_id' => $firstBalance->cell_id,
                'location_code' => $firstBalance->cell_id === null ? $firstBalance->location_code : null,
                'batch_number' => $firstBalance->batch_number,
            ]);
        }
    }

    public function applyApprovedQuantity(
        InventoryAct $act,
        InventoryActItem $item,
        float $actualQuantity
    ): void {
        $query = WarehouseBalance::query()
            ->where('organization_id', $act->organization_id)
            ->where('warehouse_id', $act->warehouse_id)
            ->where('material_id', $item->material_id)
            ->where('unit_price', $item->unit_price);

        $item->batch_number !== null
            ? $query->where('batch_number', $item->batch_number)
            : $query->whereNull('batch_number');

        if ($item->cell_id !== null) {
            $query->where('cell_id', $item->cell_id);
        } else {
            $query->whereNull('cell_id');

            if ($item->location_code !== null) {
                $query->where('location_code', $item->location_code);
            } else {
                $query->whereNull('location_code');
            }
        }

        $balances = $query->orderBy('id')->lockForUpdate()->get();

        if ($balances->isEmpty()) {
            if ($actualQuantity <= 0) {
                return;
            }

            WarehouseBalance::create([
                'organization_id' => $act->organization_id,
                'warehouse_id' => $act->warehouse_id,
                'material_id' => $item->material_id,
                'available_quantity' => $actualQuantity,
                'reserved_quantity' => 0,
                'unit_price' => $item->unit_price,
                'min_stock_level' => 0,
                'max_stock_level' => 0,
                'cell_id' => $item->cell_id,
                'location_code' => $item->location_code,
                'batch_number' => $item->batch_number,
                'last_movement_at' => now(),
                'created_at' => now(),
            ]);

            return;
        }

        $reservedQuantity = (float) $balances->sum('reserved_quantity');

        if ($actualQuantity < $reservedQuantity) {
            throw new InventoryReservationConflictException;
        }

        $remainingAvailableQuantity = max(0, $actualQuantity - $reservedQuantity);
        $lastIndex = $balances->count() - 1;

        foreach ($balances->values() as $index => $balance) {
            $newAvailableQuantity = $index === $lastIndex
                ? max(0, $remainingAvailableQuantity)
                : min((float) $balance->available_quantity, max(0, $remainingAvailableQuantity));

            $balance->available_quantity = $newAvailableQuantity;
            $balance->last_movement_at = now();
            $balance->save();

            $remainingAvailableQuantity -= $newAvailableQuantity;
        }
    }
}
