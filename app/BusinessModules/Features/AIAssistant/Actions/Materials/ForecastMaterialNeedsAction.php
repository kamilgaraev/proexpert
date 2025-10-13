<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Materials;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ForecastMaterialNeedsAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $forecastDays = $params['forecast_days'] ?? 30;
        $historicalDays = 90;
        
        $startDate = Carbon::now()->subDays($historicalDays);
        
        $historicalUsage = DB::table('material_write_offs')
            ->join('materials', 'material_write_offs.material_id', '=', 'materials.id')
            ->join('projects', 'material_write_offs.project_id', '=', 'projects.id')
            ->leftJoin('measurement_units', 'materials.measurement_unit_id', '=', 'measurement_units.id')
            ->where('projects.organization_id', $organizationId)
            ->where('material_write_offs.write_off_date', '>=', $startDate)
            ->whereNull('materials.deleted_at')
            ->whereNull('material_write_offs.deleted_at')
            ->select(
                'materials.id',
                'materials.name',
                'materials.code',
                'materials.default_price',
                'measurement_units.short_name as unit',
                DB::raw('SUM(material_write_offs.quantity) as total_used'),
                DB::raw('COUNT(DISTINCT material_write_offs.id) as usage_count')
            )
            ->groupBy(
                'materials.id',
                'materials.name',
                'materials.code',
                'materials.default_price',
                'measurement_units.short_name'
            )
            ->orderByDesc('total_used')
            ->get();

        // Переключено на warehouse_balances - суммируем по всем складам организации
        $currentStock = DB::table('warehouse_balances')
            ->join('organization_warehouses', 'warehouse_balances.warehouse_id', '=', 'organization_warehouses.id')
            ->where('warehouse_balances.organization_id', $organizationId)
            ->where('organization_warehouses.is_active', true)
            ->select(
                'warehouse_balances.material_id',
                DB::raw('SUM(warehouse_balances.available_quantity) as available')
            )
            ->groupBy('warehouse_balances.material_id')
            ->pluck('available', 'material_id');

        $forecast = [];
        $recommendations = [];

        foreach ($historicalUsage as $material) {
            $totalUsed = (float)$material->total_used;
            $avgDailyUsage = $totalUsed / $historicalDays;
            $projectedUsage = $avgDailyUsage * $forecastDays;
            $currentAvailable = (float)($currentStock[$material->id] ?? 0);
            $shortage = $projectedUsage - $currentAvailable;

            $forecast[] = [
                'material_id' => $material->id,
                'material_name' => $material->name,
                'code' => $material->code,
                'unit' => $material->unit ?? 'шт',
                'current_stock' => round($currentAvailable, 2),
                'avg_daily_usage' => round($avgDailyUsage, 3),
                'projected_usage' => round($projectedUsage, 2),
                'shortage' => $shortage > 0 ? round($shortage, 2) : 0,
                'days_until_stockout' => $avgDailyUsage > 0 ? round($currentAvailable / $avgDailyUsage, 0) : null,
            ];

            if ($shortage > 0) {
                $recommendations[] = [
                    'material_name' => $material->name,
                    'code' => $material->code,
                    'action' => 'Требуется закупка',
                    'quantity_needed' => round($shortage, 2),
                    'unit' => $material->unit ?? 'шт',
                    'estimated_cost' => round($shortage * ($material->default_price ?? 0), 2),
                    'priority' => $currentAvailable <= 0 ? 'high' : ($currentAvailable < $projectedUsage * 0.3 ? 'medium' : 'low'),
                ];
            } elseif ($avgDailyUsage > 0 && $currentAvailable / $avgDailyUsage < 30) {
                $recommendations[] = [
                    'material_name' => $material->name,
                    'code' => $material->code,
                    'action' => 'Скоро потребуется закупка',
                    'days_remaining' => round($currentAvailable / $avgDailyUsage, 0),
                    'unit' => $material->unit ?? 'шт',
                    'priority' => 'low',
                ];
            }
        }

        return [
            'forecast_period_days' => $forecastDays,
            'historical_period_days' => $historicalDays,
            'forecast' => $forecast,
            'materials_at_risk' => count($recommendations),
            'recommendations' => $recommendations,
        ];
    }
}

