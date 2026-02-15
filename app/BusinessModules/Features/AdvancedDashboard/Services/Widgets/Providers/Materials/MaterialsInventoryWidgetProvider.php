<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class MaterialsInventoryWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::MATERIALS_INVENTORY;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        // Переключено на warehouse_balances вместо material_balances
        $balances = DB::table('warehouse_balances')
            ->join('organization_warehouses', 'warehouse_balances.warehouse_id', '=', 'organization_warehouses.id')
            ->join('materials', 'warehouse_balances.material_id', '=', 'materials.id')
            ->where('warehouse_balances.organization_id', $request->organizationId)
            ->where('organization_warehouses.is_active', true)
            ->select(
                'materials.id',
                'materials.name',
                'materials.measurement_unit_id',
                DB::raw('SUM(warehouse_balances.available_quantity) as total_quantity'),
                DB::raw('SUM(warehouse_balances.available_quantity * warehouse_balances.unit_price) as total_value_calc'),
                'materials.default_price'
            )
            ->groupBy('materials.id', 'materials.name', 'materials.measurement_unit_id', 'materials.default_price')
            ->orderByDesc('total_quantity')
            ->get();

        $totalValue = 0;

        $inventory = $balances->map(function($b) use (&$totalValue) {
            $totalQty = (float)$b->total_quantity;
            $calcValue = (float)$b->total_value_calc;
            
            // Если остатка нет, цену берем из default_price
            if ($totalQty > 0) {
                $avgPrice = $calcValue / $totalQty;
            } else {
                $avgPrice = (float)$b->default_price;
                $calcValue = 0;
            }
            
            $totalValue += $calcValue;

            return [
                'material_id' => $b->id,
                'material_name' => $b->name,
                'quantity' => $totalQty,
                'unit_price' => $avgPrice,
                'total_value' => $calcValue,
            ];
        })->toArray();

        return [
            'inventory' => $inventory,
            'total_items' => count($inventory),
            'total_value' => $totalValue,
        ];
    }
}

