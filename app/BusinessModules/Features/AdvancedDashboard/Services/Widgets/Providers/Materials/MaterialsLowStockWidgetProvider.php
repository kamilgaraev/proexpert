<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class MaterialsLowStockWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::MATERIALS_LOW_STOCK;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $threshold = $request->getParam('threshold', 10);

        // Переключено на warehouse_balances вместо material_balances
        $lowStock = DB::table('warehouse_balances')
            ->join('organization_warehouses', 'warehouse_balances.warehouse_id', '=', 'organization_warehouses.id')
            ->join('materials', 'warehouse_balances.material_id', '=', 'materials.id')
            ->where('warehouse_balances.organization_id', $request->organizationId)
            ->where('organization_warehouses.is_active', true)
            ->select(
                'materials.id',
                'materials.name',
                DB::raw('SUM(warehouse_balances.available_quantity) as total_quantity'),
                DB::raw('MIN(warehouse_balances.min_stock_level) as min_stock')
            )
            ->groupBy('materials.id', 'materials.name')
            ->having('total_quantity', '<=', $threshold)
            ->orderBy('total_quantity')
            ->get();

        return [
            'low_stock_items' => $lowStock->map(fn($item) => [
                'material_id' => $item->id,
                'material_name' => $item->name,
                'current_quantity' => (float)$item->total_quantity,
                'threshold' => $threshold,
                'status' => $item->total_quantity == 0 ? 'out_of_stock' : 'low_stock',
            ])->toArray(),
            'total_items' => $lowStock->count(),
        ];
    }
}

