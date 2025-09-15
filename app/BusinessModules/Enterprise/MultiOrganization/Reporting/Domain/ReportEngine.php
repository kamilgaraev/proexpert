<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain;

use App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Models\HoldingAggregate;
use App\Models\OrganizationGroup;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ReportEngine
{
    private DataAggregator $dataAggregator;
    private KPICalculator $kpiCalculator;

    public function __construct(
        DataAggregator $dataAggregator,
        KPICalculator $kpiCalculator
    ) {
        $this->dataAggregator = $dataAggregator;
        $this->kpiCalculator = $kpiCalculator;
    }

    /**
     * Основной дашборд холдинга - ГЛАВНАЯ ФУНКЦИЯ
     */
    public function generateHoldingDashboard(int $holdingId, ?Carbon $period = null): array
    {
        $cacheKey = "holding_dashboard:{$holdingId}:" . ($period?->format('Y-m-d') ?? 'current');
        
        return Cache::remember($cacheKey, 3600, function () use ($holdingId, $period) {
            $group = OrganizationGroup::findOrFail($holdingId);
            $holding = new HoldingAggregate($group);
            
            return [
                'holding_info' => [
                    'id' => $holding->getId(),
                    'name' => $holding->getName(),
                    'slug' => $holding->getSlug(),
                    'organizations_count' => $holding->getOrganizationCount(),
                ],
                'summary_metrics' => $holding->getConsolidatedMetrics(),
                'financial_overview' => $this->buildFinancialOverview($holding, $period),
                'organizations_performance' => $this->buildOrganizationsPerformance($holding, $period),
                'kpi_dashboard' => $this->kpiCalculator->calculateHoldingKPIs($holding, $period),
                'recent_activity' => $this->getRecentActivity($holding),
                'generated_at' => now()->toISOString(),
            ];
        });
    }

    /**
     * Сравнение организаций внутри холдинга
     */
    public function generateOrganizationComparison(int $holdingId, ?Carbon $period = null): array
    {
        $group = OrganizationGroup::findOrFail($holdingId);
        $holding = new HoldingAggregate($group);
        
        $organizations = $holding->getAllOrganizations();
        $comparison = [];

        foreach ($organizations as $org) {
            $metrics = $this->dataAggregator->getOrganizationMetrics($org, $period);
            $performanceScore = $this->kpiCalculator->calculatePerformanceScore($org, $period);
            
            $comparison[] = [
                'organization' => [
                    'id' => $org->id,
                    'name' => $org->name,
                    'type' => $org->organization_type ?? 'parent',
                ],
                'metrics' => $metrics,
                'performance_score' => $performanceScore,
                'ranking' => 0, // Будет заполнен после сортировки
            ];
        }

        // Сортируем по performance_score и добавляем рейтинг
        usort($comparison, fn($a, $b) => $b['performance_score'] <=> $a['performance_score']);
        
        foreach ($comparison as $index => &$org) {
            $org['ranking'] = $index + 1;
        }

        return [
            'holding_id' => $holdingId,
            'period' => $period?->format('Y-m-d') ?? 'current',
            'organizations' => $comparison,
            'top_performer' => $comparison[0] ?? null,
            'needs_attention' => array_filter($comparison, fn($org) => $org['performance_score'] < 60),
            'total_organizations' => count($comparison),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Финансовый отчет (только то, что действительно работает)
     */
    public function generateFinancialReport(int $holdingId, Carbon $startDate, Carbon $endDate): array
    {
        $group = OrganizationGroup::findOrFail($holdingId);
        $holding = new HoldingAggregate($group);

        $totalRevenue = $this->dataAggregator->getRevenueForPeriod($holding, $startDate, $endDate);
        $totalExpenses = $this->dataAggregator->getExpensesForPeriod($holding, $startDate, $endDate);
        $netProfit = $totalRevenue - $totalExpenses;

        return [
            'report_type' => 'financial',
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'days_count' => $startDate->diffInDays($endDate) + 1,
            ],
            'summary' => [
                'total_revenue' => $totalRevenue,
                'total_expenses' => $totalExpenses,
                'net_profit' => $netProfit,
                'profit_margin' => $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 2) : 0,
            ],
            'breakdown_by_organization' => $this->getFinancialBreakdownByOrganization($holding, $startDate, $endDate),
            'monthly_dynamics' => $this->getMonthlyFinancialDynamics($holding, $startDate, $endDate),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Построение финансового обзора для дашборда
     */
    private function buildFinancialOverview(HoldingAggregate $holding, ?Carbon $period): array
    {
        $currentPeriod = $period ?? now();
        $previousPeriod = $currentPeriod->copy()->subMonth();

        $currentRevenue = $this->dataAggregator->getTotalRevenue($holding, $currentPeriod);
        $previousRevenue = $this->dataAggregator->getTotalRevenue($holding, $previousPeriod);
        
        $revenueGrowth = $previousRevenue > 0 
            ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 2)
            : ($currentRevenue > 0 ? 100 : 0);

        return [
            'total_revenue' => $currentRevenue,
            'revenue_growth' => $revenueGrowth,
            'contracts_value' => $holding->getTotalContractsValue(),
            'active_contracts' => $holding->getActiveContractsCount(),
            'profit_margin' => $this->kpiCalculator->calculateProfitMargin($holding, $currentPeriod),
            'revenue_by_organization' => $this->dataAggregator->getRevenueByOrganization($holding, $currentPeriod),
        ];
    }

    /**
     * Построение производительности организаций для дашборда
     */
    private function buildOrganizationsPerformance(HoldingAggregate $holding, ?Carbon $period): array
    {
        $organizations = $holding->getAllOrganizations();
        $performance = [];

        foreach ($organizations as $org) {
            $metrics = $this->dataAggregator->getOrganizationMetrics($org, $period);
            $efficiencyScore = $this->kpiCalculator->calculateEfficiencyScore($org, $period);

            $performance[] = [
                'organization_id' => $org->id,
                'organization_name' => $org->name,
                'organization_type' => $org->organization_type ?? 'parent',
                'projects_count' => $metrics['projects_count'],
                'active_contracts' => $metrics['active_contracts'],
                'revenue' => $metrics['revenue'],
                'users_count' => $metrics['users_count'],
                'efficiency_score' => $efficiencyScore,
                'status' => $this->determineOrganizationStatus($efficiencyScore),
            ];
        }

        // Сортируем по efficiency_score
        usort($performance, fn($a, $b) => $b['efficiency_score'] <=> $a['efficiency_score']);

        return $performance;
    }

    /**
     * Получение последней активности холдинга
     */
    private function getRecentActivity(HoldingAggregate $holding): array
    {
        $activities = [];
        $organizations = $holding->getAllOrganizations();

        foreach ($organizations as $org) {
            // Последние 2 проекта
            $recentProjects = $org->projects()
                ->latest()
                ->limit(2)
                ->get(['id', 'name', 'created_at']);

            foreach ($recentProjects as $project) {
                $activities[] = [
                    'type' => 'project_created',
                    'organization_id' => $org->id,
                    'organization_name' => $org->name,
                    'title' => "Создан проект: {$project->name}",
                    'date' => $project->created_at,
                    'importance' => 'medium',
                ];
            }

            // Последние 2 контракта
            $recentContracts = $org->contracts()
                ->latest()
                ->limit(2)
                ->get(['id', 'name', 'total_amount', 'created_at']);

            foreach ($recentContracts as $contract) {
                $importance = $contract->total_amount > 1000000 ? 'high' : 'medium';
                
                $activities[] = [
                    'type' => 'contract_signed',
                    'organization_id' => $org->id,
                    'organization_name' => $org->name,
                    'title' => "Подписан контракт: {$contract->name}",
                    'amount' => $contract->total_amount,
                    'date' => $contract->created_at,
                    'importance' => $importance,
                ];
            }
        }

        // Сортируем по дате и важности
        usort($activities, function($a, $b) {
            if ($a['date']->eq($b['date'])) {
                $importanceOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
                return $importanceOrder[$b['importance']] <=> $importanceOrder[$a['importance']];
            }
            return $b['date'] <=> $a['date'];
        });

        return array_slice($activities, 0, 8); // Топ 8 событий
    }

    /**
     * Детализация финансов по организациям
     */
    private function getFinancialBreakdownByOrganization(HoldingAggregate $holding, Carbon $startDate, Carbon $endDate): array
    {
        $breakdown = [];
        $totalRevenue = 0;
        
        foreach ($holding->getAllOrganizations() as $organization) {
            $revenue = $organization->contracts()
                ->whereDate('created_at', '>=', $startDate)
                ->whereDate('created_at', '<=', $endDate)
                ->sum('total_amount') ?? 0;
                
            $totalRevenue += $revenue;
            
            $breakdown[] = [
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'organization_type' => $organization->organization_type ?? 'parent',
                'revenue' => $revenue,
                'contracts_count' => $organization->contracts()
                    ->whereDate('created_at', '>=', $startDate)
                    ->whereDate('created_at', '<=', $endDate)
                    ->count(),
            ];
        }
        
        // Добавляем процентное соотношение
        foreach ($breakdown as &$item) {
            $item['revenue_percentage'] = $totalRevenue > 0 
                ? round(($item['revenue'] / $totalRevenue) * 100, 2) 
                : 0;
        }
        
        // Сортируем по убыванию выручки
        usort($breakdown, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        
        return $breakdown;
    }

    /**
     * Динамика финансов по месяцам
     */
    private function getMonthlyFinancialDynamics(HoldingAggregate $holding, Carbon $startDate, Carbon $endDate): array
    {
        $dynamics = [];
        $current = $startDate->copy()->startOfMonth();
        
        while ($current->lte($endDate)) {
            $monthEnd = $current->copy()->endOfMonth();
            $monthRevenue = 0;
            $monthContracts = 0;
            
            foreach ($holding->getAllOrganizations() as $organization) {
                $monthlyContracts = $organization->contracts()
                    ->whereDate('created_at', '>=', $current)
                    ->whereDate('created_at', '<=', $monthEnd);
                    
                $monthRevenue += $monthlyContracts->sum('total_amount') ?? 0;
                $monthContracts += $monthlyContracts->count();
            }
            
            $dynamics[] = [
                'period' => $current->format('Y-m'),
                'month_name' => $current->translatedFormat('F Y'),
                'revenue' => $monthRevenue,
                'contracts_count' => $monthContracts,
                'avg_contract_value' => $monthContracts > 0 ? round($monthRevenue / $monthContracts, 2) : 0,
            ];
            
            $current->addMonth();
        }
        
        return $dynamics;
    }

    /**
     * Определение статуса организации по показателям эффективности
     */
    private function determineOrganizationStatus(float $efficiencyScore): string
    {
        return match (true) {
            $efficiencyScore >= 80 => 'excellent',
            $efficiencyScore >= 60 => 'good',
            $efficiencyScore >= 40 => 'needs_improvement',
            default => 'critical'
        };
    }
}