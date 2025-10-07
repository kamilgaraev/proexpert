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
        $materialCostsQuery = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to]);
        
        if ($projectId) {
            $materialCostsQuery->where('completed_works.project_id', $projectId);
        }
        
        $materialCosts = $materialCostsQuery->sum('completed_works.material_cost') ?? 0;
        
        $laborCostsQuery = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to]);
        
        if ($projectId) {
            $laborCostsQuery->where('completed_works.project_id', $projectId);
        }
        
        $laborCosts = $laborCostsQuery->sum(DB::raw('completed_works.quantity * completed_works.unit_price * 0.3')) ?? 0;
        
        $contractorCostsQuery = DB::table('materials')
            ->join('projects', 'materials.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('materials.created_at', [$from, $to])
            ->where('materials.supplier_type', 'contractor');
        
        if ($projectId) {
            $contractorCostsQuery->where('materials.project_id', $projectId);
        }
        
        $contractorCosts = $contractorCostsQuery->sum(DB::raw('materials.quantity * materials.price')) ?? 0;
        
        return $materialCosts + $laborCosts + $contractorCosts;
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
        $query = Contract::where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to]);
        
        if ($projectId) {
            $query->where('project_id', $projectId);
        }
        
        $contracts = $query->sum('contract_amount') ?? 0;
        
        $advancePaymentsQuery = DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to]);
        
        if ($projectId) {
            $advancePaymentsQuery->where('project_id', $projectId);
        }
        
        $advancePayments = $advancePaymentsQuery->sum('advance_payment') ?? 0;
        
        $completedWorksQuery = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to]);
        
        if ($projectId) {
            $completedWorksQuery->where('completed_works.project_id', $projectId);
        }
        
        $workPayments = $completedWorksQuery->sum(DB::raw('completed_works.quantity * completed_works.unit_price')) ?? 0;
        
        return [
            [
                'category' => 'Контракты',
                'amount' => $contracts,
                'percentage' => $contracts + $advancePayments + $workPayments > 0 
                    ? round(($contracts / ($contracts + $advancePayments + $workPayments)) * 100, 2) 
                    : 0
            ],
            [
                'category' => 'Авансовые платежи',
                'amount' => $advancePayments,
                'percentage' => $contracts + $advancePayments + $workPayments > 0 
                    ? round(($advancePayments / ($contracts + $advancePayments + $workPayments)) * 100, 2) 
                    : 0
            ],
            [
                'category' => 'Оплата за работы',
                'amount' => $workPayments,
                'percentage' => $contracts + $advancePayments + $workPayments > 0 
                    ? round(($workPayments / ($contracts + $advancePayments + $workPayments)) * 100, 2) 
                    : 0
            ],
        ];
    }

    /**
     * Получить разбивку оттока по категориям
     */
    protected function getOutflowByCategory(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): array
    {
        $materialCostsQuery = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to]);
        
        if ($projectId) {
            $materialCostsQuery->where('completed_works.project_id', $projectId);
        }
        
        $materialCosts = $materialCostsQuery->sum('completed_works.material_cost') ?? 0;
        
        $laborCostsQuery = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to]);
        
        if ($projectId) {
            $laborCostsQuery->where('completed_works.project_id', $projectId);
        }
        
        $laborCosts = $laborCostsQuery->sum(DB::raw('completed_works.quantity * completed_works.unit_price * 0.3')) ?? 0;
        
        $contractorCostsQuery = DB::table('materials')
            ->join('projects', 'materials.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('materials.created_at', [$from, $to])
            ->where('materials.supplier_type', 'contractor');
        
        if ($projectId) {
            $contractorCostsQuery->where('materials.project_id', $projectId);
        }
        
        $contractorCosts = $contractorCostsQuery->sum(DB::raw('materials.quantity * materials.price')) ?? 0;
        
        $total = $materialCosts + $laborCosts + $contractorCosts;
        
        return [
            [
                'category' => 'Материалы',
                'amount' => $materialCosts,
                'percentage' => $total > 0 ? round(($materialCosts / $total) * 100, 2) : 0
            ],
            [
                'category' => 'Зарплаты',
                'amount' => $laborCosts,
                'percentage' => $total > 0 ? round(($laborCosts / $total) * 100, 2) : 0
            ],
            [
                'category' => 'Подрядчики',
                'amount' => $contractorCosts,
                'percentage' => $total > 0 ? round(($contractorCosts / $total) * 100, 2) : 0
            ],
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
        $current = Carbon::now()->startOfMonth();
        $forecast = [];
        
        for ($i = 0; $i < $months; $i++) {
            $monthStart = $current->copy()->addMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            
            $contractsInMonth = Contract::where('organization_id', $organizationId)
                ->where(function($query) use ($monthStart, $monthEnd) {
                    $query->whereBetween('start_date', [$monthStart, $monthEnd])
                        ->orWhereBetween('end_date', [$monthStart, $monthEnd])
                        ->orWhere(function($q) use ($monthStart, $monthEnd) {
                            $q->where('start_date', '<=', $monthStart)
                              ->where('end_date', '>=', $monthEnd);
                        });
                })
                ->whereIn('status', ['active', 'in_progress', 'planned'])
                ->get();
            
            $totalAmount = 0;
            
            foreach ($contractsInMonth as $contract) {
                $contractAmount = $contract->contract_amount ?? 0;
                $duration = Carbon::parse($contract->start_date)->diffInMonths(Carbon::parse($contract->end_date)) ?: 1;
                $monthlyAmount = $contractAmount / $duration;
                
                $totalAmount += $monthlyAmount;
            }
            
            $forecast[] = [
                'month' => $monthStart->format('Y-m'),
                'amount' => round($totalAmount, 2),
                'contracts_count' => $contractsInMonth->count(),
            ];
        }
        
        return $forecast;
    }

    /**
     * Рассчитать прогноз на основе тренда (линейная регрессия)
     */
    protected function calculateTrendForecast(array $historicalData, int $months): array
    {
        if (empty($historicalData) || count($historicalData) < 2) {
            return [];
        }
        
        $n = count($historicalData);
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;
        
        foreach ($historicalData as $index => $data) {
            $x = $index + 1;
            $y = $data['amount'];
            
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        $avgGrowth = $slope / ($sumY / $n);
        
        $forecast = [];
        $current = Carbon::now()->startOfMonth();
        $lastAmount = end($historicalData)['amount'];
        
        for ($i = 0; $i < $months; $i++) {
            $x = $n + $i + 1;
            $forecastAmount = $slope * $x + $intercept;
            
            $forecastAmount = max(0, $forecastAmount);
            
            $forecast[] = [
                'month' => $current->copy()->addMonths($i)->format('Y-m'),
                'amount' => round($forecastAmount, 2),
            ];
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
        $now = Carbon::now();
        
        $contracts = Contract::where('organization_id', $organizationId)
            ->whereIn('status', ['active', 'in_progress'])
            ->get();
        
        $total = 0;
        $current = 0;
        $overdue30 = 0;
        $overdue60 = 0;
        $overdue90Plus = 0;
        $byContract = [];
        
        foreach ($contracts as $contract) {
            $contractAmount = $contract->contract_amount ?? 0;
            $advancePaid = $contract->advance_payment ?? 0;
            $workCompleted = CompletedWork::where('contract_id', $contract->id)
                ->sum(DB::raw('quantity * unit_price')) ?? 0;
            
            $receivable = $contractAmount - $advancePaid - $workCompleted;
            
            if ($receivable <= 0) {
                continue;
            }
            
            $total += $receivable;
            
            $paymentDueDate = $contract->payment_due_date 
                ? Carbon::parse($contract->payment_due_date) 
                : ($contract->end_date ? Carbon::parse($contract->end_date)->addDays(30) : $now);
            
            $daysOverdue = $now->diffInDays($paymentDueDate, false);
            
            if ($daysOverdue >= 0) {
                $current += $receivable;
                $status = 'current';
            } elseif ($daysOverdue >= -30) {
                $overdue30 += $receivable;
                $status = 'overdue_30';
            } elseif ($daysOverdue >= -60) {
                $overdue60 += $receivable;
                $status = 'overdue_60';
            } else {
                $overdue90Plus += $receivable;
                $status = 'overdue_90_plus';
            }
            
            $byContract[] = [
                'contract_id' => $contract->id,
                'contract_name' => $contract->name ?? "Контракт #{$contract->id}",
                'amount' => $receivable,
                'due_date' => $paymentDueDate->toISOString(),
                'days_overdue' => max(0, abs($daysOverdue)),
                'status' => $status,
            ];
        }
        
        usort($byContract, fn($a, $b) => $b['amount'] <=> $a['amount']);
        
        return [
            'total' => $total,
            'current' => $current,
            'overdue_30' => $overdue30,
            'overdue_60' => $overdue60,
            'overdue_90_plus' => $overdue90Plus,
            'by_contract' => $byContract,
        ];
    }

    /**
     * Рассчитать кредиторскую задолженность
     */
    protected function calculatePayables(int $organizationId): array
    {
        $now = Carbon::now();
        
        $materials = Material::join('projects', 'materials.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->where('materials.status', '!=', 'paid')
            ->select('materials.*')
            ->get();
        
        $total = 0;
        $current = 0;
        $overdue30 = 0;
        $overdue60 = 0;
        $overdue90Plus = 0;
        $bySupplier = [];
        
        $supplierMap = [];
        
        foreach ($materials as $material) {
            $payable = ($material->quantity ?? 0) * ($material->price ?? 0);
            
            if ($payable <= 0) {
                continue;
            }
            
            $total += $payable;
            
            $supplier = $material->supplier ?? 'Неизвестный поставщик';
            
            $paymentDueDate = $material->payment_due_date 
                ? Carbon::parse($material->payment_due_date) 
                : Carbon::parse($material->created_at)->addDays(30);
            
            $daysOverdue = $now->diffInDays($paymentDueDate, false);
            
            if ($daysOverdue >= 0) {
                $current += $payable;
                $status = 'current';
            } elseif ($daysOverdue >= -30) {
                $overdue30 += $payable;
                $status = 'overdue_30';
            } elseif ($daysOverdue >= -60) {
                $overdue60 += $payable;
                $status = 'overdue_60';
            } else {
                $overdue90Plus += $payable;
                $status = 'overdue_90_plus';
            }
            
            if (!isset($supplierMap[$supplier])) {
                $supplierMap[$supplier] = [
                    'supplier' => $supplier,
                    'total_amount' => 0,
                    'items' => [],
                ];
            }
            
            $supplierMap[$supplier]['total_amount'] += $payable;
            $supplierMap[$supplier]['items'][] = [
                'material_id' => $material->id,
                'material_name' => $material->name ?? "Материал #{$material->id}",
                'amount' => $payable,
                'due_date' => $paymentDueDate->toISOString(),
                'days_overdue' => max(0, abs($daysOverdue)),
                'status' => $status,
            ];
        }
        
        $bySupplier = array_values($supplierMap);
        usort($bySupplier, fn($a, $b) => $b['total_amount'] <=> $a['total_amount']);
        
        return [
            'total' => $total,
            'current' => $current,
            'overdue_30' => $overdue30,
            'overdue_60' => $overdue60,
            'overdue_90_plus' => $overdue90Plus,
            'by_supplier' => $bySupplier,
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

