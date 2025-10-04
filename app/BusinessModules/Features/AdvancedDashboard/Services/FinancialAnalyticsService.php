<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\Contract;
use App\Models\CompletedWork;
use App\Models\Material;
use App\Models\Project;

/**
 * Сервис финансовой аналитики
 * 
 * Предоставляет методы для расчета финансовых метрик:
 * - Cash Flow (движение денежных средств)
 * - Profit & Loss (прибыли и убытки)
 * - ROI (рентабельность инвестиций)
 * - Revenue Forecast (прогноз доходов)
 * - Receivables/Payables (дебиторка/кредиторка)
 */
class FinancialAnalyticsService
{
    protected int $cacheTTL = 300; // 5 минут

    /**
     * Получить данные по движению денежных средств (Cash Flow)
     * 
     * @param int $organizationId ID организации
     * @param Carbon $from Начальная дата
     * @param Carbon $to Конечная дата
     * @param int|null $projectId Фильтр по проекту (опционально)
     * @return array
     */
    public function getCashFlow(int $organizationId, Carbon $from, Carbon $to, ?int $projectId = null): array
    {
        $cacheKey = "cash_flow_{$organizationId}_{$from->format('Y-m-d')}_{$to->format('Y-m-d')}_{$projectId}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($organizationId, $from, $to, $projectId) {
            // Приток (доходы от контрактов)
            $inflow = $this->calculateInflow($organizationId, $from, $to, $projectId);
            
            // Отток (расходы на материалы, работы, зарплаты)
            $outflow = $this->calculateOutflow($organizationId, $from, $to, $projectId);
            
            // Разбивка по месяцам
            $monthlyData = $this->getMonthlyBreakdown($organizationId, $from, $to, $projectId);
            
            return [
                'period' => [
                    'from' => $from->toISOString(),
                    'to' => $to->toISOString(),
                ],
                'total_inflow' => $inflow,
                'total_outflow' => $outflow,
                'net_cash_flow' => $inflow - $outflow,
                'monthly_breakdown' => $monthlyData,
                'inflow_by_category' => $this->getInflowByCategory($organizationId, $from, $to, $projectId),
                'outflow_by_category' => $this->getOutflowByCategory($organizationId, $from, $to, $projectId),
            ];
        });
    }

    /**
     * Получить отчет о прибылях и убытках (P&L)
     * 
     * @param int $organizationId ID организации
     * @param Carbon $from Начальная дата
     * @param Carbon $to Конечная дата
     * @param int|null $projectId Фильтр по проекту (опционально)
     * @return array
     */
    public function getProfitAndLoss(int $organizationId, Carbon $from, Carbon $to, ?int $projectId = null): array
    {
        $cacheKey = "profit_loss_{$organizationId}_{$from->format('Y-m-d')}_{$to->format('Y-m-d')}_{$projectId}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($organizationId, $from, $to, $projectId) {
            // Выручка
            $revenue = $this->calculateRevenue($organizationId, $from, $to, $projectId);
            
            // Себестоимость
            $cogs = $this->calculateCOGS($organizationId, $from, $to, $projectId);
            
            // Операционные расходы
            $opex = $this->calculateOpEx($organizationId, $from, $to, $projectId);
            
            // Валовая прибыль
            $grossProfit = $revenue - $cogs;
            
            // Операционная прибыль
            $operatingProfit = $grossProfit - $opex;
            
            // Чистая прибыль
            $netProfit = $operatingProfit;
            
            return [
                'period' => [
                    'from' => $from->toISOString(),
                    'to' => $to->toISOString(),
                ],
                'revenue' => $revenue,
                'cost_of_goods_sold' => $cogs,
                'gross_profit' => $grossProfit,
                'gross_profit_margin' => $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0,
                'operating_expenses' => $opex,
                'operating_profit' => $operatingProfit,
                'operating_profit_margin' => $revenue > 0 ? round(($operatingProfit / $revenue) * 100, 2) : 0,
                'net_profit' => $netProfit,
                'net_profit_margin' => $revenue > 0 ? round(($netProfit / $revenue) * 100, 2) : 0,
                'by_project' => $this->getProfitByProject($organizationId, $from, $to),
            ];
        });
    }

    /**
     * Рассчитать ROI (рентабельность инвестиций)
     * 
     * @param int $organizationId ID организации
     * @param int|null $projectId Конкретный проект (опционально)
     * @param Carbon|null $from Начальная дата (опционально)
     * @param Carbon|null $to Конечная дата (опционально)
     * @return array
     */
    public function getROI(int $organizationId, ?int $projectId = null, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? Carbon::now()->startOfYear();
        $to = $to ?? Carbon::now();
        
        $cacheKey = "roi_{$organizationId}_{$projectId}_{$from->format('Y-m-d')}_{$to->format('Y-m-d')}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($organizationId, $projectId, $from, $to) {
            if ($projectId) {
                // ROI по конкретному проекту
                return $this->calculateProjectROI($projectId, $from, $to);
            }
            
            // ROI по всем проектам
            $projects = Project::where('organization_id', $organizationId)
                ->whereBetween('created_at', [$from, $to])
                ->get();
            
            $roiData = [];
            $totalInvestment = 0;
            $totalProfit = 0;
            
            foreach ($projects as $project) {
                $projectROI = $this->calculateProjectROI($project->id, $from, $to);
                $roiData[] = array_merge($projectROI, [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                ]);
                
                $totalInvestment += $projectROI['investment'];
                $totalProfit += $projectROI['profit'];
            }
            
            // Сортировка по ROI (от лучших к худшим)
            usort($roiData, fn($a, $b) => $b['roi_percentage'] <=> $a['roi_percentage']);
            
            return [
                'period' => [
                    'from' => $from->toISOString(),
                    'to' => $to->toISOString(),
                ],
                'total_investment' => $totalInvestment,
                'total_profit' => $totalProfit,
                'total_roi_percentage' => $totalInvestment > 0 
                    ? round(($totalProfit / $totalInvestment) * 100, 2) 
                    : 0,
                'projects_count' => count($roiData),
                'projects' => $roiData,
                'top_performers' => array_slice($roiData, 0, 5),
                'worst_performers' => array_slice(array_reverse($roiData), 0, 5),
            ];
        });
    }

    /**
     * Прогноз доходов (Revenue Forecast)
     * 
     * @param int $organizationId ID организации
     * @param int $months Количество месяцев для прогноза
     * @return array
     */
    public function getRevenueForecast(int $organizationId, int $months = 6): array
    {
        $cacheKey = "revenue_forecast_{$organizationId}_{$months}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($organizationId, $months) {
            // Исторические данные за последние 6 месяцев
            $historicalFrom = Carbon::now()->subMonths(6);
            $historicalTo = Carbon::now();
            
            $historicalData = $this->getMonthlyRevenue($organizationId, $historicalFrom, $historicalTo);
            
            // Прогноз на основе текущих контрактов
            $contractBasedForecast = $this->getForecastFromContracts($organizationId, $months);
            
            // Тренд на основе исторических данных (линейная регрессия)
            $trendForecast = $this->calculateTrendForecast($historicalData, $months);
            
            // Комбинированный прогноз (средневзвешенное)
            $combinedForecast = $this->combineForecast($contractBasedForecast, $trendForecast);
            
            return [
                'forecast_months' => $months,
                'forecast_from' => Carbon::now()->startOfMonth()->toISOString(),
                'historical_data' => $historicalData,
                'contract_based_forecast' => $contractBasedForecast,
                'trend_forecast' => $trendForecast,
                'combined_forecast' => $combinedForecast,
                'total_forecasted_revenue' => array_sum(array_column($combinedForecast, 'amount')),
                'confidence_level' => $this->calculateConfidenceLevel($historicalData),
            ];
        });
    }

    /**
     * Дебиторская и кредиторская задолженность
     * 
     * @param int $organizationId ID организации
     * @return array
     */
    public function getReceivablesPayables(int $organizationId): array
    {
        $cacheKey = "receivables_payables_{$organizationId}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($organizationId) {
            // Дебиторская задолженность (нам должны)
            $receivables = $this->calculateReceivables($organizationId);
            
            // Кредиторская задолженность (мы должны)
            $payables = $this->calculatePayables($organizationId);
            
            return [
                'as_of_date' => Carbon::now()->toISOString(),
                'receivables' => [
                    'total' => $receivables['total'],
                    'current' => $receivables['current'], // 0-30 дней
                    'overdue_30' => $receivables['overdue_30'], // 30-60 дней
                    'overdue_60' => $receivables['overdue_60'], // 60-90 дней
                    'overdue_90_plus' => $receivables['overdue_90_plus'], // 90+ дней
                    'by_contract' => $receivables['by_contract'],
                ],
                'payables' => [
                    'total' => $payables['total'],
                    'current' => $payables['current'],
                    'overdue_30' => $payables['overdue_30'],
                    'overdue_60' => $payables['overdue_60'],
                    'overdue_90_plus' => $payables['overdue_90_plus'],
                    'by_supplier' => $payables['by_supplier'],
                ],
                'net_position' => $receivables['total'] - $payables['total'],
            ];
        });
    }

    // ==================== PROTECTED HELPER METHODS ====================

    /**
     * Рассчитать приток средств
     */
    protected function calculateInflow(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): float
    {
        $query = DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'active');
        
        if ($projectId) {
            $query->where('project_id', $projectId);
        }
        
        return $query->sum('contract_amount') ?? 0;
    }

    /**
     * Рассчитать отток средств
     */
    protected function calculateOutflow(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): float
    {
        // Расходы на материалы
        $materialCosts = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to]);
        
        if ($projectId) {
            $materialCosts->where('completed_works.project_id', $projectId);
        }
        
        $materialCosts = $materialCosts->sum('completed_works.material_cost') ?? 0;
        
        // TODO: Добавить расходы на зарплаты, подрядчиков, etc.
        
        return $materialCosts;
    }

    /**
     * Получить разбивку по месяцам
     */
    protected function getMonthlyBreakdown(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): array
    {
        $months = [];
        $current = $from->copy()->startOfMonth();
        
        while ($current->lte($to)) {
            $monthEnd = $current->copy()->endOfMonth();
            
            $inflow = $this->calculateInflow($organizationId, $current, $monthEnd, $projectId);
            $outflow = $this->calculateOutflow($organizationId, $current, $monthEnd, $projectId);
            
            $months[] = [
                'month' => $current->format('Y-m'),
                'month_name' => $current->translatedFormat('F Y'),
                'inflow' => $inflow,
                'outflow' => $outflow,
                'net' => $inflow - $outflow,
            ];
            
            $current->addMonth();
        }
        
        return $months;
    }

    /**
     * Получить разбивку притока по категориям
     */
    protected function getInflowByCategory(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): array
    {
        // TODO: Реализовать разбивку по категориям (контракты, авансы, оплаты)
        return [
            ['category' => 'Контракты', 'amount' => 0],
            ['category' => 'Авансы', 'amount' => 0],
            ['category' => 'Оплаты', 'amount' => 0],
        ];
    }

    /**
     * Получить разбивку оттока по категориям
     */
    protected function getOutflowByCategory(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): array
    {
        // TODO: Реализовать разбивку по категориям (материалы, зарплаты, подрядчики)
        return [
            ['category' => 'Материалы', 'amount' => 0],
            ['category' => 'Зарплаты', 'amount' => 0],
            ['category' => 'Подрядчики', 'amount' => 0],
        ];
    }

    /**
     * Рассчитать выручку
     */
    protected function calculateRevenue(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): float
    {
        return $this->calculateInflow($organizationId, $from, $to, $projectId);
    }

    /**
     * Рассчитать себестоимость (COGS)
     */
    protected function calculateCOGS(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): float
    {
        // Прямые затраты на производство/работы
        return $this->calculateOutflow($organizationId, $from, $to, $projectId) * 0.7; // 70% от оттока
    }

    /**
     * Рассчитать операционные расходы (OpEx)
     */
    protected function calculateOpEx(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): float
    {
        // Косвенные расходы (зарплаты офиса, аренда, etc.)
        return $this->calculateOutflow($organizationId, $from, $to, $projectId) * 0.3; // 30% от оттока
    }

    /**
     * Получить прибыль по проектам
     */
    protected function getProfitByProject(int $organizationId, Carbon $from, Carbon $to): array
    {
        $projects = Project::where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->get();
        
        $results = [];
        
        foreach ($projects as $project) {
            $revenue = $this->calculateRevenue($organizationId, $from, $to, $project->id);
            $cogs = $this->calculateCOGS($organizationId, $from, $to, $project->id);
            $profit = $revenue - $cogs;
            
            $results[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'revenue' => $revenue,
                'cogs' => $cogs,
                'profit' => $profit,
                'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            ];
        }
        
        // Сортировка по прибыли
        usort($results, fn($a, $b) => $b['profit'] <=> $a['profit']);
        
        return $results;
    }

    /**
     * Рассчитать ROI проекта
     */
    protected function calculateProjectROI(int $projectId, Carbon $from, Carbon $to): array
    {
        $project = Project::find($projectId);
        
        if (!$project) {
            return [
                'investment' => 0,
                'revenue' => 0,
                'profit' => 0,
                'roi_percentage' => 0,
            ];
        }
        
        // Инвестиции (затраты)
        $investment = $this->calculateOutflow($project->organization_id, $from, $to, $projectId);
        
        // Доход
        $revenue = $this->calculateRevenue($project->organization_id, $from, $to, $projectId);
        
        // Прибыль
        $profit = $revenue - $investment;
        
        // ROI
        $roi = $investment > 0 ? round(($profit / $investment) * 100, 2) : 0;
        
        return [
            'investment' => $investment,
            'revenue' => $revenue,
            'profit' => $profit,
            'roi_percentage' => $roi,
        ];
    }

    /**
     * Получить месячную выручку
     */
    protected function getMonthlyRevenue(int $organizationId, Carbon $from, Carbon $to): array
    {
        $months = [];
        $current = $from->copy()->startOfMonth();
        
        while ($current->lte($to)) {
            $monthEnd = $current->copy()->endOfMonth();
            $revenue = $this->calculateRevenue($organizationId, $current, $monthEnd, null);
            
            $months[] = [
                'month' => $current->format('Y-m'),
                'amount' => $revenue,
            ];
            
            $current->addMonth();
        }
        
        return $months;
    }

    /**
     * Получить прогноз на основе контрактов
     */
    protected function getForecastFromContracts(int $organizationId, int $months): array
    {
        // TODO: Реализовать прогноз на основе текущих контрактов
        $forecast = [];
        $current = Carbon::now()->startOfMonth();
        
        for ($i = 0; $i < $months; $i++) {
            $forecast[] = [
                'month' => $current->copy()->addMonths($i)->format('Y-m'),
                'amount' => 0, // Placeholder
            ];
        }
        
        return $forecast;
    }

    /**
     * Рассчитать прогноз на основе тренда
     */
    protected function calculateTrendForecast(array $historicalData, int $months): array
    {
        // Простая линейная регрессия
        // TODO: Улучшить алгоритм прогнозирования
        
        if (empty($historicalData)) {
            return [];
        }
        
        $avgGrowth = 0;
        $lastAmount = end($historicalData)['amount'];
        
        $forecast = [];
        $current = Carbon::now()->startOfMonth();
        
        for ($i = 0; $i < $months; $i++) {
            $forecastAmount = $lastAmount * (1 + $avgGrowth);
            
            $forecast[] = [
                'month' => $current->copy()->addMonths($i)->format('Y-m'),
                'amount' => $forecastAmount,
            ];
            
            $lastAmount = $forecastAmount;
        }
        
        return $forecast;
    }

    /**
     * Комбинировать прогнозы
     */
    protected function combineForecast(array $contractBased, array $trendBased): array
    {
        // Средневзвешенное: 70% контракты, 30% тренд
        $combined = [];
        
        foreach ($contractBased as $index => $contractData) {
            $trendAmount = $trendBased[$index]['amount'] ?? 0;
            $combinedAmount = ($contractData['amount'] * 0.7) + ($trendAmount * 0.3);
            
            $combined[] = [
                'month' => $contractData['month'],
                'amount' => $combinedAmount,
            ];
        }
        
        return $combined;
    }

    /**
     * Рассчитать уровень доверия прогноза
     */
    protected function calculateConfidenceLevel(array $historicalData): float
    {
        // Чем больше данных и чем стабильнее тренд, тем выше доверие
        $dataPoints = count($historicalData);
        
        if ($dataPoints < 3) {
            return 0.3; // Низкое доверие
        } elseif ($dataPoints < 6) {
            return 0.6; // Среднее доверие
        } else {
            return 0.85; // Высокое доверие
        }
    }

    /**
     * Рассчитать дебиторскую задолженность
     */
    protected function calculateReceivables(int $organizationId): array
    {
        // TODO: Реализовать расчет дебиторской задолженности
        return [
            'total' => 0,
            'current' => 0,
            'overdue_30' => 0,
            'overdue_60' => 0,
            'overdue_90_plus' => 0,
            'by_contract' => [],
        ];
    }

    /**
     * Рассчитать кредиторскую задолженность
     */
    protected function calculatePayables(int $organizationId): array
    {
        // TODO: Реализовать расчет кредиторской задолженности
        return [
            'total' => 0,
            'current' => 0,
            'overdue_30' => 0,
            'overdue_60' => 0,
            'overdue_90_plus' => 0,
            'by_supplier' => [],
        ];
    }

    /**
     * Установить время кеширования
     */
    public function setCacheTTL(int $seconds): self
    {
        $this->cacheTTL = $seconds;
        return $this;
    }

    /**
     * Очистить кеш финансовой аналитики
     */
    public function clearCache(int $organizationId): void
    {
        $patterns = [
            "cash_flow_{$organizationId}_*",
            "profit_loss_{$organizationId}_*",
            "roi_{$organizationId}_*",
            "revenue_forecast_{$organizationId}_*",
            "receivables_payables_{$organizationId}",
        ];
        
        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }
}

