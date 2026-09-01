<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use Illuminate\Support\Collection;

final class WarehouseStorageLoadSummaryService
{
    public function summarizeCells(int $organizationId, int $warehouseId): array
    {
        $cells = WarehouseStorageCell::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->get(['id', 'zone_id', 'code', 'capacity', 'is_active']);

        if ($cells->isEmpty()) {
            return [];
        }

        $cellIdByCode = $cells
            ->filter(fn (WarehouseStorageCell $cell): bool => filled($cell->code))
            ->pluck('id', 'code');
        $quantitiesByCell = [];

        $rows = WarehouseBalance::query()
            ->leftJoin('materials', 'materials.id', '=', 'warehouse_balances.material_id')
            ->leftJoin('measurement_units', 'measurement_units.id', '=', 'materials.measurement_unit_id')
            ->where('warehouse_balances.organization_id', $organizationId)
            ->where('warehouse_balances.warehouse_id', $warehouseId)
            ->where(function ($query) use ($cells, $cellIdByCode): void {
                $query->whereIn('warehouse_balances.cell_id', $cells->pluck('id'))
                    ->orWhere(function ($legacyQuery) use ($cellIdByCode): void {
                        $legacyQuery->whereNull('warehouse_balances.cell_id')
                            ->whereIn('warehouse_balances.location_code', $cellIdByCode->keys());
                    });
            })
            ->selectRaw(
                'warehouse_balances.cell_id, warehouse_balances.location_code, materials.measurement_unit_id, '
                .'measurement_units.short_name, measurement_units.name, '
                .'SUM(warehouse_balances.available_quantity + warehouse_balances.reserved_quantity) AS quantity'
            )
            ->groupBy([
                'warehouse_balances.cell_id',
                'warehouse_balances.location_code',
                'materials.measurement_unit_id',
                'measurement_units.short_name',
                'measurement_units.name',
            ])
            ->get();

        foreach ($rows as $row) {
            $quantity = (float) $row->quantity;
            if ($quantity === 0.0) {
                continue;
            }

            $cellId = $row->cell_id !== null
                ? (int) $row->cell_id
                : (int) ($cellIdByCode->get((string) $row->location_code) ?? 0);
            if ($cellId === 0) {
                continue;
            }

            $unitId = $row->measurement_unit_id !== null ? (int) $row->measurement_unit_id : null;
            $unitKey = $unitId !== null ? 'unit-'.$unitId : 'unknown';
            $unitName = $unitId !== null
                ? trim((string) ($row->short_name ?: $row->name))
                : null;

            if (! isset($quantitiesByCell[$cellId][$unitKey])) {
                $quantitiesByCell[$cellId][$unitKey] = [
                    'measurement_unit_id' => $unitId,
                    'measurement_unit' => $unitName !== '' ? $unitName : null,
                    'quantity' => 0.0,
                ];
            }

            $quantitiesByCell[$cellId][$unitKey]['quantity'] += $quantity;
        }

        return $cells
            ->mapWithKeys(function (WarehouseStorageCell $cell) use ($quantitiesByCell): array {
                $breakdown = collect($quantitiesByCell[$cell->id] ?? [])->values();

                return [$cell->id => $this->makeSummary($breakdown, $cell->capacity) + [
                    'zone_id' => $cell->zone_id !== null ? (int) $cell->zone_id : null,
                    'capacity' => $cell->capacity !== null ? (float) $cell->capacity : null,
                    'is_active' => (bool) $cell->is_active,
                ]];
            })
            ->all();
    }

    public function summarizeZones(int $organizationId, int $warehouseId): array
    {
        return collect($this->summarizeCells($organizationId, $warehouseId))
            ->whereNotNull('zone_id')
            ->groupBy('zone_id')
            ->map(function (Collection $cells): array {
                $breakdown = $cells
                    ->flatMap(fn (array $cell): array => $cell['quantity_breakdown'])
                    ->groupBy(fn (array $item): string => $item['measurement_unit_id'] !== null
                        ? 'unit-'.$item['measurement_unit_id']
                        : 'unknown')
                    ->map(function (Collection $items): array {
                        $first = $items->first();

                        return [
                            'measurement_unit_id' => $first['measurement_unit_id'],
                            'measurement_unit' => $first['measurement_unit'],
                            'quantity' => round((float) $items->sum('quantity'), 3),
                        ];
                    })
                    ->values();
                $capacities = $cells->pluck('capacity');
                $capacity = $capacities->contains(null)
                    ? null
                    : (float) $capacities->sum();

                $summary = $this->makeSummary($breakdown, $capacity);
                $comparableCapacity = $summary['has_mixed_measurement_units']
                    || $summary['has_incomplete_measurement_units']
                    ? null
                    : $capacity;

                return $summary + [
                    'cells_count' => $cells->count(),
                    'active_cells_count' => $cells->where('is_active', true)->count(),
                    'capacity' => $comparableCapacity,
                ];
            })
            ->all();
    }

    private function makeSummary(Collection $breakdown, mixed $capacity): array
    {
        $normalizedBreakdown = $breakdown
            ->map(fn (array $item): array => [
                'measurement_unit_id' => $item['measurement_unit_id'],
                'measurement_unit' => $item['measurement_unit'],
                'quantity' => round((float) $item['quantity'], 3),
            ])
            ->values();
        $knownUnits = $normalizedBreakdown->whereNotNull('measurement_unit_id');
        $hasIncompleteUnits = $normalizedBreakdown->contains(
            fn (array $item): bool => $item['measurement_unit_id'] === null || $item['measurement_unit'] === null
        );
        $hasMixedUnits = $knownUnits->pluck('measurement_unit_id')->unique()->count() > 1;
        $hasComparableQuantity = ! $hasIncompleteUnits && ! $hasMixedUnits;
        $storedQuantity = $hasComparableQuantity
            ? round((float) $normalizedBreakdown->sum('quantity'), 3)
            : null;
        $measurementUnit = $hasComparableQuantity && $knownUnits->isNotEmpty()
            ? $knownUnits->first()['measurement_unit']
            : null;
        $numericCapacity = $capacity !== null ? (float) $capacity : null;

        return [
            'stored_quantity' => $storedQuantity,
            'measurement_unit' => $measurementUnit,
            'quantity_breakdown' => $normalizedBreakdown->all(),
            'has_mixed_measurement_units' => $hasMixedUnits,
            'has_incomplete_measurement_units' => $hasIncompleteUnits,
            'current_utilization' => $storedQuantity !== null && $numericCapacity !== null && $numericCapacity > 0
                ? round(min(100, ($storedQuantity / $numericCapacity) * 100), 1)
                : null,
        ];
    }
}
