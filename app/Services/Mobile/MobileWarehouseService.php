<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

class MobileWarehouseService
{
    public function build(User $user): array
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_warehouse.errors.no_organization'));
        }

        $warehouseStats = WarehouseBalance::query()
            ->where('organization_id', $organizationId)
            ->selectRaw(
                'warehouse_id, ' .
                'COUNT(CASE WHEN available_quantity > 0 THEN 1 END) as unique_items_count, ' .
                'SUM(CASE WHEN available_quantity > 0 THEN available_quantity * unit_price ELSE 0 END) as total_value'
            )
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        $warehouses = OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->active()
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        $balancesQuery = WarehouseBalance::query()->where('organization_id', $organizationId);
        $recentMovements = WarehouseMovement::query()
            ->where('organization_id', $organizationId)
            ->with([
                'warehouse:id,name',
                'material:id,name,measurement_unit_id',
                'material.measurementUnit:id,name',
                'project:id,name',
            ])
            ->orderByDesc('movement_date')
            ->limit(10)
            ->get();

        return [
            'summary' => [
                'warehouse_count' => $warehouses->count(),
                'unique_items_count' => (int) ((clone $balancesQuery)->where('available_quantity', '>', 0)->count()),
                'low_stock_count' => (int) ((clone $balancesQuery)->lowStock()->count()),
                'reserved_items_count' => (int) ((clone $balancesQuery)->where('reserved_quantity', '>', 0)->count()),
                'recent_movements_count' => (int) WarehouseMovement::query()
                    ->where('organization_id', $organizationId)
                    ->where('movement_date', '>=', now()->subDays(7))
                    ->count(),
                'total_value' => round((float) ((clone $balancesQuery)
                    ->selectRaw('COALESCE(SUM(available_quantity * unit_price), 0) as total_value')
                    ->value('total_value') ?? 0), 2),
            ],
            'warehouses' => $this->mapWarehouses($warehouses, $warehouseStats),
            'recent_movements' => $this->mapRecentMovements($recentMovements),
        ];
    }

    public function buildWidget(User $user): array
    {
        $data = $this->build($user);
        $summary = $data['summary'];
        $badge = $summary['low_stock_count'] > 0
            ? (string) $summary['low_stock_count']
            : ($summary['recent_movements_count'] > 0 ? (string) $summary['recent_movements_count'] : null);

        return [
            'description' => trans_message('mobile_dashboard.widgets.warehouse.description', [
                'warehouses' => $summary['warehouse_count'],
                'low_stock' => $summary['low_stock_count'],
            ]),
            'badge' => $badge,
            'payload' => $data,
        ];
    }

    private function mapWarehouses(Collection $warehouses, Collection $warehouseStats): array
    {
        return $warehouses->map(function (OrganizationWarehouse $warehouse) use ($warehouseStats): array {
            $stats = $warehouseStats->get($warehouse->id);

            return [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'address' => $warehouse->address,
                'is_main' => (bool) $warehouse->is_main,
                'warehouse_type' => $warehouse->warehouse_type,
                'unique_items_count' => (int) ($stats?->unique_items_count ?? 0),
                'total_value' => round((float) ($stats?->total_value ?? 0), 2),
            ];
        })->values()->all();
    }

    private function mapRecentMovements(Collection $movements): array
    {
        return $movements->map(function (WarehouseMovement $movement): array {
            return [
                'id' => $movement->id,
                'movement_type' => $movement->movement_type,
                'movement_type_label' => trans_message('mobile_warehouse.movement_types.' . $movement->movement_type),
                'warehouse_name' => $movement->warehouse?->name,
                'material_name' => $movement->material?->name,
                'measurement_unit' => $movement->material?->measurementUnit?->name,
                'project_name' => $movement->project?->name,
                'quantity' => (float) $movement->quantity,
                'price' => (float) $movement->price,
                'document_number' => $movement->document_number,
                'reason' => $movement->reason,
                'movement_date' => $movement->movement_date?->toIso8601String(),
                'photo_gallery' => $movement->photo_gallery,
            ];
        })->values()->all();
    }
}
