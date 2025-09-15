<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain;

use App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Models\HoldingAggregate;
use App\Models\Organization;
use Carbon\Carbon;

class KPICalculator
{
    private DataAggregator $dataAggregator;

    public function __construct(DataAggregator $dataAggregator)
    {
        $this->dataAggregator = $dataAggregator;
    }

    /**
     * Расчет основных KPI холдинга
     */
    public function calculateHoldingKPIs(HoldingAggregate $holding, ?Carbon $period = null): array
    {
        $projectsStats = $this->dataAggregator->getProjectsStatistics($holding, $period);
        $workforceStats = $this->dataAggregator->getWorkforceStatistics($holding);
        $revenue = $this->dataAggregator->getTotalRevenue($holding, $period);
        $expenses = $this->dataAggregator->getTotalExpenses($holding, $period);

        return [
            'financial_kpis' => [
                'revenue_growth' => $this->calculateRevenueGrowth($holding, $period),
                'profit_margin' => $this->calculateProfitMargin($holding, $period),
                'roi' => $this->calculateROI($revenue, $expenses),
                'revenue_per_employee' => $workforceStats['active_users'] > 0 
                    ? round($revenue / $workforceStats['active_users'], 2) 
                    : 0,
            ],
            'operational_kpis' => [
                'project_completion_rate' => $projectsStats['completion_rate'],
                'projects_per_employee' => $workforceStats['active_users'] > 0 
                    ? round($projectsStats['total_projects'] / $workforceStats['active_users'], 2) 
                    : 0,
                'capacity_utilization' => $this->calculateCapacityUtilization($holding, $workforceStats, $projectsStats),
                'efficiency_score' => $this->calculateOverallEfficiency($revenue, $workforceStats, $projectsStats),
            ],
            'growth_kpis' => [
                'organizations_count' => $holding->getOrganizationCount(),
                'monthly_growth' => $this->calculateMonthlyGrowth($holding, $period),
                'market_expansion_index' => $this->calculateMarketExpansion($holding),
            ],
        ];
    }

    /**
     * Расчет показателя эффективности организации
     */
    public function calculatePerformanceScore(Organization $organization, ?Carbon $period = null): float
    {
        $metrics = $this->dataAggregator->getOrganizationMetrics($organization, $period);
        
        // Нормализованные веса для различных метрик
        $weights = [
            'revenue_score' => 0.35,
            'projects_score' => 0.25,
            'efficiency_score' => 0.25,
            'completion_score' => 0.15,
        ];

        $scores = [
            'revenue_score' => $this->normalizeRevenue($metrics['revenue']),
            'projects_score' => $this->normalizeProjects($metrics['projects_count']),
            'efficiency_score' => $this->calculateEfficiencyScore($organization, $period),
            'completion_score' => $metrics['completion_rate'],
        ];

        $totalScore = 0;
        foreach ($weights as $metric => $weight) {
            $totalScore += $scores[$metric] * $weight;
        }

        return round($totalScore, 2);
    }

    /**
     * Расчет эффективности организации
     */
    public function calculateEfficiencyScore(Organization $organization, ?Carbon $period = null): float
    {
        $metrics = $this->dataAggregator->getOrganizationMetrics($organization, $period);
        
        $revenuePerUser = $metrics['revenue_per_user'];
        $completionRate = $metrics['completion_rate'];
        $projectsPerUser = $metrics['users_count'] > 0 
            ? round($metrics['projects_count'] / $metrics['users_count'], 2) 
            : 0;

        // Нормализация и взвешивание показателей
        $efficiencyScore = (
            $this->normalizeMetric($revenuePerUser, 500000, 2000000) * 0.4 +
            $this->normalizeMetric($completionRate, 50, 90) * 0.35 +
            $this->normalizeMetric($projectsPerUser, 1, 5) * 0.25
        );

        return round($efficiencyScore, 2);
    }

    /**
     * Расчет рентабельности холдинга
     */
    public function calculateProfitMargin(HoldingAggregate $holding, ?Carbon $period = null): float
    {
        $revenue = $this->dataAggregator->getTotalRevenue($holding, $period);
        $expenses = $this->dataAggregator->getTotalExpenses($holding, $period);

        if ($revenue == 0) return 0;

        return round((($revenue - $expenses) / $revenue) * 100, 2);
    }

    /**
     * Расчет рентабельности за конкретный период
     */
    public function calculateProfitMarginForPeriod(HoldingAggregate $holding, Carbon $startDate, Carbon $endDate): float
    {
        $revenue = $this->dataAggregator->getRevenueForPeriod($holding, $startDate, $endDate);
        $expenses = $this->dataAggregator->getExpensesForPeriod($holding, $startDate, $endDate);

        if ($revenue == 0) return 0;

        return round((($revenue - $expenses) / $revenue) * 100, 2);
    }

    /**
     * Расчет роста выручки
     */
    private function calculateRevenueGrowth(HoldingAggregate $holding, ?Carbon $period = null): float
    {
        $currentPeriod = $period ?? now();
        $previousPeriod = $currentPeriod->copy()->subMonth();

        $currentRevenue = $this->dataAggregator->getTotalRevenue($holding, $currentPeriod);
        $previousRevenue = $this->dataAggregator->getTotalRevenue($holding, $previousPeriod);

        if ($previousRevenue == 0) {
            return $currentRevenue > 0 ? 100 : 0;
        }

        return round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 2);
    }

    /**
     * Расчет ROI
     */
    private function calculateROI(float $revenue, float $expenses): float
    {
        if ($expenses == 0) return 0;
        
        $profit = $revenue - $expenses;
        return round(($profit / $expenses) * 100, 2);
    }

    /**
     * Расчет загрузки мощностей
     */
    private function calculateCapacityUtilization(HoldingAggregate $holding, array $workforceStats, array $projectsStats): float
    {
        $maxCapacity = $workforceStats['active_users'] * 2; // Каждый сотрудник может работать над 2 проектами
        $currentLoad = $projectsStats['active_projects'];

        return $maxCapacity > 0 ? round(($currentLoad / $maxCapacity) * 100, 2) : 0;
    }

    /**
     * Общая эффективность
     */
    private function calculateOverallEfficiency(float $revenue, array $workforceStats, array $projectsStats): float
    {
        $revenuePerUser = $workforceStats['active_users'] > 0 
            ? $revenue / $workforceStats['active_users'] 
            : 0;
        
        $revenuePerProject = $projectsStats['total_projects'] > 0 
            ? $revenue / $projectsStats['total_projects'] 
            : 0;

        // Комбинированный показатель эффективности
        $efficiency = (
            $this->normalizeMetric($revenuePerUser, 500000, 2000000) * 0.6 +
            $this->normalizeMetric($revenuePerProject, 1000000, 5000000) * 0.4
        );

        return round($efficiency, 2);
    }

    /**
     * Месячный рост
     */
    private function calculateMonthlyGrowth(HoldingAggregate $holding, ?Carbon $period = null): float
    {
        $trend = $this->dataAggregator->getMonthlyRevenueTrend($holding, $period);
        
        if (count($trend) < 2) return 0;
        
        $lastMonth = end($trend);
        $prevMonth = prev($trend);
        
        if ($prevMonth['revenue'] == 0) {
            return $lastMonth['revenue'] > 0 ? 100 : 0;
        }
        
        return round((($lastMonth['revenue'] - $prevMonth['revenue']) / $prevMonth['revenue']) * 100, 2);
    }

    /**
     * Индекс расширения рынка
     */
    private function calculateMarketExpansion(HoldingAggregate $holding): float
    {
        $organizations = $holding->getAllOrganizations();
        $uniqueAddresses = $organizations->pluck('address')->unique()->filter()->count();
        $uniqueCities = $organizations->pluck('city')->unique()->filter()->count();
        
        // Простой индекс на основе географического разнообразия
        return round(($uniqueAddresses + $uniqueCities * 2) * 5, 2);
    }

    /**
     * Нормализация выручки в диапазон 0-100
     */
    private function normalizeRevenue(float $revenue): float
    {
        return $this->normalizeMetric($revenue, 0, 10000000); // 10M как максимум
    }

    /**
     * Нормализация количества проектов в диапазон 0-100
     */
    private function normalizeProjects(int $projectsCount): float
    {
        return $this->normalizeMetric($projectsCount, 0, 50); // 50 проектов как максимум
    }

    /**
     * Универсальная нормализация метрики в диапазон 0-100
     */
    private function normalizeMetric(float $value, float $min, float $max): float
    {
        if ($max <= $min) return 0;
        
        $normalized = (($value - $min) / ($max - $min)) * 100;
        return round(max(0, min(100, $normalized)), 2);
    }
}