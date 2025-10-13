<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Materials;

use Illuminate\Support\Facades\DB;

class CheckMaterialStockAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        // Переключено на warehouse_balances вместо material_balances
        $materials = DB::table('materials')
            ->leftJoin('warehouse_balances', 'materials.id', '=', 'warehouse_balances.material_id')
            ->leftJoin('organization_warehouses', function($join) use ($organizationId) {
                $join->on('warehouse_balances.warehouse_id', '=', 'organization_warehouses.id')
                     ->where('organization_warehouses.organization_id', '=', $organizationId);
            })
            ->leftJoin('measurement_units', 'materials.measurement_unit_id', '=', 'measurement_units.id')
            ->where('materials.organization_id', $organizationId)
            ->where('materials.is_active', true)
            ->whereNull('materials.deleted_at')
            ->select(
                'materials.id',
                'materials.name',
                'materials.code',
                'materials.category',
                'materials.default_price',
                'measurement_units.short_name as unit',
                DB::raw('COALESCE(SUM(warehouse_balances.available_quantity), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(warehouse_balances.reserved_quantity), 0) as reserved_quantity'),
                DB::raw('COALESCE(AVG(warehouse_balances.average_price), materials.default_price, 0) as avg_price')
            )
            ->groupBy(
                'materials.id',
                'materials.name',
                'materials.code',
                'materials.category',
                'materials.default_price',
                'measurement_units.short_name'
            )
            ->get();

        $lowStockItems = [];
        $totalValue = 0;
        $allMaterials = [];

        foreach ($materials as $material) {
            $quantity = (float)$material->total_quantity;
            $reserved = (float)$material->reserved_quantity;
            $available = $quantity;  // available_quantity уже не включает зарезервированное
            $price = (float)($material->avg_price ?? $material->default_price ?? 0);
            $value = $available * $price;
            
            $totalValue += $value;

            $materialData = [
                'id' => $material->id,
                'name' => $material->name,
                'code' => $material->code,
                'category' => $material->category,
                'quantity' => $quantity,
                'reserved' => $reserved,
                'available' => $available,
                'unit' => $material->unit ?? 'шт',
                'price' => $price,
                'value' => $value,
            ];

            $allMaterials[] = $materialData;

            if ($available < 10) {
                $lowStockItems[] = $materialData;
            }
        }

        usort($allMaterials, function($a, $b) {
            return $b['value'] <=> $a['value'];
        });

        return [
            'materials' => $allMaterials,
            'low_stock_items' => $lowStockItems,
            'low_stock_count' => count($lowStockItems),
            'total_materials' => count($allMaterials),
            'total_inventory_value' => round($totalValue, 2),
        ];
    }
}

