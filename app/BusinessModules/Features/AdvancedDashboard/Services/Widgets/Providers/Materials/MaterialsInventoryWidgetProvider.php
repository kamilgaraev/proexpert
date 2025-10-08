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
        $balances = DB::table('material_balances')
            ->join('projects', 'material_balances.project_id', '=', 'projects.id')
            ->join('materials', 'material_balances.material_id', '=', 'materials.id')
            ->where('projects.organization_id', $request->organizationId)
            ->select(
                'materials.id',
                'materials.name',
                'materials.measurement_unit_id',
                DB::raw('SUM(material_balances.quantity) as total_quantity'),
                'materials.default_price'
            )
            ->groupBy('materials.id', 'materials.name', 'materials.measurement_unit_id', 'materials.default_price')
            ->orderByDesc('total_quantity')
            ->get();

        $totalValue = 0;

        $inventory = $balances->map(function($b) use (&$totalValue) {
            $value = (float)($b->total_quantity * ($b->default_price ?? 0));
            $totalValue += $value;

            return [
                'material_id' => $b->id,
                'material_name' => $b->name,
                'quantity' => (float)$b->total_quantity,
                'unit_price' => (float)($b->default_price ?? 0),
                'total_value' => $value,
            ];
        })->toArray();

        return [
            'inventory' => $inventory,
            'total_items' => count($inventory),
            'total_value' => $totalValue,
        ];
    }
}

