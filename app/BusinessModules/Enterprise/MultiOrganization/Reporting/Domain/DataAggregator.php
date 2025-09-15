<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain;

use App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Models\HoldingAggregate;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DataAggregator
{
    /**
     * Получение метрик организации с кэшированием
     */
    public function getOrganizationMetrics(Organization $organization, ?Carbon $period = null): array
    {
        $cacheKey = "org_metrics:{$organization->id}:" . ($period?->format('Y-m-d') ?? 'current');
        
        return Cache::remember($cacheKey, 1800, function () use ($organization, $period) {
            $baseQuery = [];
            if ($period) {
                $startOfMonth = $period->copy()->startOfMonth();
                $endOfMonth = $period->copy()->endOfMonth();
                $baseQuery = [
                    ['created_at', '>=', $startOfMonth],
                    ['created_at', '<=', $endOfMonth]
                ];
            }

            // Получаем все данные одним запросом для оптимизации
            $projects = $organization->projects();
            $contracts = $organization->contracts();
            
            if ($period) {
                $projects = $projects->whereBetween('created_at', [$startOfMonth, $endOfMonth]);
                $contracts = $contracts->whereBetween('created_at', [$startOfMonth, $endOfMonth]);
            }

            $projectsCount = $projects->count();
            $completedProjects = $projects->where('status', 'completed')->count();
            $contractsData = $contracts->get(['status', 'total_amount']);
            
            $revenue = $contractsData->sum('total_amount') ?? 0;
            $activeContracts = $contractsData->where('status', 'active')->count();
            $usersCount = $organization->users()->wherePivot('is_active', true)->count();

            return [
                'revenue' => $revenue,
                'projects_count' => $projectsCount,
                'completed_projects' => $completedProjects,
                'active_contracts' => $activeContracts,
                'total_contracts' => $contractsData->count(),
                'users_count' => $usersCount,
                'contracts_value' => $revenue,
                'completion_rate' => $projectsCount > 0 ? round(($completedProjects / $projectsCount) * 100, 2) : 0,
                'revenue_per_user' => $usersCount > 0 ? round($revenue / $usersCount, 2) : 0,
            ];
        });
    }

    /**
     * Получение выручки организации за период
     */
    public function getOrganizationRevenue(Organization $organization, ?Carbon $period = null): float
    {
        $query = $organization->contracts();
        
        if ($period) {
            $query->whereBetween('created_at', [
                $period->copy()->startOfMonth(),
                $period->copy()->endOfMonth()
            ]);
        }
        
        return $query->sum('total_amount') ?? 0;
    }

    /**
     * Общая выручка по холдингу
     */
    public function getTotalRevenue(HoldingAggregate $holding, ?Carbon $period = null): float
    {
        $cacheKey = "holding_revenue:{$holding->getId()}:" . ($period?->format('Y-m-d') ?? 'current');
        
        return Cache::remember($cacheKey, 1800, function () use ($holding, $period) {
            $totalRevenue = 0;
            
            foreach ($holding->getAllOrganizations() as $organization) {
                $totalRevenue += $this->getOrganizationRevenue($organization, $period);
            }
            
            return $totalRevenue;
        });
    }

    /**
     * Расходы (упрощенный расчет на основе выручки)
     */
    public function getTotalExpenses(HoldingAggregate $holding, ?Carbon $period = null): float
    {
        $revenue = $this->getTotalRevenue($holding, $period);
        // Упрощенный расчет: 70% от выручки составляют расходы
        return $revenue * 0.7;
    }

    /**
     * Распределение выручки по организациям
     */
    public function getRevenueByOrganization(HoldingAggregate $holding, ?Carbon $period = null): array
    {
        $revenueData = [];
        $totalRevenue = 0;
        
        foreach ($holding->getAllOrganizations() as $organization) {
            $revenue = $this->getOrganizationRevenue($organization, $period);
            $totalRevenue += $revenue;
            
            $revenueData[] = [
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'organization_type' => $organization->organization_type ?? 'parent',
                'revenue' => $revenue,
                'percentage' => 0, // Будет рассчитан ниже
            ];
        }
        
        // Рассчитываем проценты
        if ($totalRevenue > 0) {
            foreach ($revenueData as &$item) {
                $item['percentage'] = round(($item['revenue'] / $totalRevenue) * 100, 2);
            }
        }
        
        // Сортируем по убыванию выручки
        usort($revenueData, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        
        return $revenueData;
    }

    /**
     * Тренд выручки по месяцам (последние 12 месяцев)
     */
    public function getMonthlyRevenueTrend(HoldingAggregate $holding, ?Carbon $period = null): array
    {
        $endDate = $period ?? now();
        $startDate = $endDate->copy()->subMonths(11)->startOfMonth();
        
        $monthlyData = [];
        $current = $startDate->copy();
        
        while ($current->lte($endDate)) {
            $monthRevenue = $this->getTotalRevenue($holding, $current);
            
            $monthlyData[] = [
                'period' => $current->format('Y-m'),
                'month_name' => $current->translatedFormat('F Y'),
                'revenue' => $monthRevenue,
                'short_month' => $current->translatedFormat('M'),
                'year' => $current->year,
            ];
            
            $current->addMonth();
        }
        
        return $monthlyData;
    }

    /**
     * Выручка за конкретный период
     */
    public function getRevenueForPeriod(HoldingAggregate $holding, Carbon $startDate, Carbon $endDate): float
    {
        $totalRevenue = 0;
        
        foreach ($holding->getAllOrganizations() as $organization) {
            $revenue = $organization->contracts()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('total_amount') ?? 0;
                
            $totalRevenue += $revenue;
        }
        
        return $totalRevenue;
    }

    /**
     * Расходы за конкретный период
     */
    public function getExpensesForPeriod(HoldingAggregate $holding, Carbon $startDate, Carbon $endDate): float
    {
        $revenue = $this->getRevenueForPeriod($holding, $startDate, $endDate);
        // Упрощенный расчет: 70% от выручки
        return $revenue * 0.7;
    }

    /**
     * Чистая прибыль за период
     */
    public function getNetProfitForPeriod(HoldingAggregate $holding, Carbon $startDate, Carbon $endDate): float
    {
        $revenue = $this->getRevenueForPeriod($holding, $startDate, $endDate);
        $expenses = $this->getExpensesForPeriod($holding, $startDate, $endDate);
        
        return $revenue - $expenses;
    }

    /**
     * Статистика по проектам
     */
    public function getProjectsStatistics(HoldingAggregate $holding, ?Carbon $period = null): array
    {
        $statistics = [
            'total_projects' => 0,
            'active_projects' => 0,
            'completed_projects' => 0,
            'planning_projects' => 0,
            'completion_rate' => 0,
        ];

        foreach ($holding->getAllOrganizations() as $organization) {
            $query = $organization->projects();
            
            if ($period) {
                $query->whereBetween('created_at', [
                    $period->copy()->startOfMonth(),
                    $period->copy()->endOfMonth()
                ]);
            }

            $projects = $query->get(['status']);
            $total = $projects->count();
            
            $statistics['total_projects'] += $total;
            $statistics['active_projects'] += $projects->where('status', 'active')->count();
            $statistics['completed_projects'] += $projects->where('status', 'completed')->count();
            $statistics['planning_projects'] += $projects->where('status', 'planning')->count();
        }

        // Рассчитываем общий процент завершения
        $statistics['completion_rate'] = $statistics['total_projects'] > 0 
            ? round(($statistics['completed_projects'] / $statistics['total_projects']) * 100, 2) 
            : 0;

        return $statistics;
    }

    /**
     * Статистика по персоналу
     */
    public function getWorkforceStatistics(HoldingAggregate $holding): array
    {
        $statistics = [
            'total_users' => 0,
            'active_users' => 0,
            'owners_count' => 0,
        ];

        foreach ($holding->getAllOrganizations() as $organization) {
            $totalUsers = $organization->users()->count();
            $activeUsers = $organization->users()->wherePivot('is_active', true)->count();
            $owners = $organization->users()->wherePivot('is_owner', true)->count();

            $statistics['total_users'] += $totalUsers;
            $statistics['active_users'] += $activeUsers;
            $statistics['owners_count'] += $owners;
        }

        return $statistics;
    }

    /**
     * Метрики эффективности
     */
    public function getEfficiencyMetrics(HoldingAggregate $holding, ?Carbon $period = null): array
    {
        $projectsStats = $this->getProjectsStatistics($holding, $period);
        $workforceStats = $this->getWorkforceStatistics($holding);
        $revenue = $this->getTotalRevenue($holding, $period);

        return [
            'projects_per_user' => $workforceStats['active_users'] > 0 
                ? round($projectsStats['total_projects'] / $workforceStats['active_users'], 2) 
                : 0,
            'revenue_per_user' => $workforceStats['active_users'] > 0 
                ? round($revenue / $workforceStats['active_users'], 2) 
                : 0,
            'project_completion_rate' => $projectsStats['completion_rate'],
            'active_projects_ratio' => $projectsStats['total_projects'] > 0 
                ? round(($projectsStats['active_projects'] / $projectsStats['total_projects']) * 100, 2) 
                : 0,
        ];
    }

    /**
     * Очистка кэша организации
     */
    public function clearOrganizationCache(int $organizationId): void
    {
        $pattern = "org_metrics:{$organizationId}:*";
        $this->clearCacheByPattern($pattern);
    }

    /**
     * Очистка кэша холдинга
     */
    public function clearHoldingCache(int $holdingId): void
    {
        $patterns = [
            "holding_dashboard:{$holdingId}:*",
            "holding_revenue:{$holdingId}:*"
        ];

        foreach ($patterns as $pattern) {
            $this->clearCacheByPattern($pattern);
        }
    }

    /**
     * Очистка кэша по паттерну
     */
    private function clearCacheByPattern(string $pattern): void
    {
        try {
            $redis = Cache::getRedis();
            $keys = $redis->keys($pattern);
            
            if (!empty($keys)) {
                $redis->del($keys);
            }
        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            Log::warning("Ошибка очистки кэша: {$e->getMessage()}", ['pattern' => $pattern]);
        }
    }
}