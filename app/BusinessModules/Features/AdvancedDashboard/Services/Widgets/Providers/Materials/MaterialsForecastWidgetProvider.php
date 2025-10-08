<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MaterialsForecastWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::MATERIALS_FORECAST;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $months = $request->getParam('months', 3);

        if (!DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
            return ['forecast' => [], 'months' => $months];
        }

        $historicalFrom = Carbon::now()->subMonths(3);
        $historicalTo = Carbon::now();

        $avgMonthlyConsumption = DB::table('completed_work_materials')
            ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->join('materials', 'completed_work_materials.material_id', '=', 'materials.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$historicalFrom, $historicalTo])
            ->select(
                'materials.id',
                'materials.name',
                DB::raw('AVG(completed_work_materials.quantity) as avg_quantity')
            )
            ->groupBy('materials.id', 'materials.name')
            ->orderByDesc('avg_quantity')
            ->limit(10)
            ->get();

        $forecast = $avgMonthlyConsumption->map(function($item) use ($months) {
            return [
                'material_id' => $item->id,
                'material_name' => $item->name,
                'forecasted_quantity' => round((float)$item->avg_quantity * $months, 2),
                'monthly_average' => round((float)$item->avg_quantity, 2),
            ];
        })->toArray();

        return [
            'forecast_months' => $months,
            'forecast' => $forecast,
        ];
    }
}

