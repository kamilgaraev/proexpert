<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CashFlowForecastWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CASH_FLOW_FORECAST;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $months = $request->getParam('months', 6);
        
        // Получаем исторические данные за последние 6 месяцев
        $historicalData = $this->getHistoricalCashFlow($request->organizationId);
        
        // Получаем планируемые поступления из контрактов
        $plannedInflows = $this->getPlannedInflows($request->organizationId, $months);
        
        // Прогнозируем расходы на основе тренда
        $forecastedOutflows = $this->forecastOutflows($historicalData, $months);
        
        // Комбинируем прогноз
        $forecast = $this->combineForecast($plannedInflows, $forecastedOutflows);
        
        return [
            'forecast_months' => $months,
            'historical_data' => $historicalData,
            'forecast' => $forecast,
            'total_forecasted_inflow' => array_sum(array_column($forecast, 'inflow')),
            'total_forecasted_outflow' => array_sum(array_column($forecast, 'outflow')),
            'net_forecast' => array_sum(array_column($forecast, 'net_flow')),
        ];
    }

    protected function getHistoricalCashFlow(int $organizationId): array
    {
        $data = [];
        $startDate = Carbon::now()->subMonths(6)->startOfMonth();
        
        for ($i = 0; $i < 6; $i++) {
            $monthStart = $startDate->copy()->addMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            
            $inflow = DB::table('contracts')
                ->where('organization_id', $organizationId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('total_amount') ?: 0;
            
            $outflow = DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->whereBetween('completed_works.created_at', [$monthStart, $monthEnd])
                ->sum(DB::raw('completed_works.quantity * completed_works.price')) ?: 0;
            
            $data[] = [
                'month' => $monthStart->format('Y-m'),
                'inflow' => (float)$inflow,
                'outflow' => (float)$outflow,
                'net_flow' => (float)($inflow - $outflow),
            ];
        }
        
        return $data;
    }

    protected function getPlannedInflows(int $organizationId, int $months): array
    {
        $inflows = [];
        $startDate = Carbon::now()->startOfMonth();
        
        for ($i = 0; $i < $months; $i++) {
            $monthStart = $startDate->copy()->addMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            
            // Контракты, которые планируются или активны в этом месяце
            $planned = DB::table('contracts')
                ->where('organization_id', $organizationId)
                ->where(function($query) use ($monthStart, $monthEnd) {
                    $query->whereBetween('start_date', [$monthStart, $monthEnd])
                          ->orWhereBetween('end_date', [$monthStart, $monthEnd])
                          ->orWhere(function($q) use ($monthStart, $monthEnd) {
                              $q->where('start_date', '<=', $monthStart)
                                ->where('end_date', '>=', $monthEnd);
                          });
                })
                ->whereIn('status', ['active', 'planned', 'in_progress'])
                ->sum('total_amount') ?: 0;
            
            $inflows[$monthStart->format('Y-m')] = (float)$planned;
        }
        
        return $inflows;
    }

    protected function forecastOutflows(array $historical, int $months): array
    {
        if (empty($historical)) {
            return array_fill(0, $months, 0);
        }
        
        // Простая линейная регрессия для прогноза расходов
        $avgOutflow = array_sum(array_column($historical, 'outflow')) / count($historical);
        
        // Тренд (последний месяц vs средний)
        $lastOutflow = end($historical)['outflow'];
        $trend = $lastOutflow > 0 ? ($lastOutflow - $avgOutflow) / $avgOutflow : 0;
        
        $outflows = [];
        $startDate = Carbon::now()->startOfMonth();
        
        for ($i = 0; $i < $months; $i++) {
            $month = $startDate->copy()->addMonths($i);
            // Прогноз = средний расход + тренд * номер месяца
            $forecast = $avgOutflow * (1 + ($trend * ($i + 1) * 0.1));
            $outflows[$month->format('Y-m')] = max(0, $forecast);
        }
        
        return $outflows;
    }

    protected function combineForecast(array $inflows, array $outflows): array
    {
        $forecast = [];
        
        foreach ($inflows as $month => $inflow) {
            $outflow = $outflows[$month] ?? 0;
            $forecast[] = [
                'month' => $month,
                'inflow' => round($inflow, 2),
                'outflow' => round($outflow, 2),
                'net_flow' => round($inflow - $outflow, 2),
            ];
        }
        
        return $forecast;
    }
}
