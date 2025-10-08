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

        $lowStock = DB::table('material_balances')
            ->join('projects', 'material_balances.project_id', '=', 'projects.id')
            ->join('materials', 'material_balances.material_id', '=', 'materials.id')
            ->where('projects.organization_id', $request->organizationId)
            ->select(
                'materials.id',
                'materials.name',
                DB::raw('SUM(material_balances.quantity) as total_quantity')
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

