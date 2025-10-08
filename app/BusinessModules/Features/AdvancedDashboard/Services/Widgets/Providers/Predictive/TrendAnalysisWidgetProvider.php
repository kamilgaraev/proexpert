<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TrendAnalysisWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::TREND_ANALYSIS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $months = $request->getParam('months', 12);
        
        $revenueTrend = $this->getRevenueTrend($request->organizationId, $months);
        $projectsTrend = $this->getProjectsTrend($request->organizationId, $months);
        $employeeTrend = $this->getEmployeeProductivityTrend($request->organizationId, $months);
        
        return [
            'period_months' => $months,
            'revenue_trend' => [
                'data' => $revenueTrend,
                'direction' => $this->getTrendDirection($revenueTrend),
                'growth_rate' => $this->calculateGrowthRate($revenueTrend),
            ],
            'projects_trend' => [
                'data' => $projectsTrend,
                'direction' => $this->getTrendDirection($projectsTrend),
            ],
            'productivity_trend' => [
                'data' => $employeeTrend,
                'direction' => $this->getTrendDirection($employeeTrend),
            ],
            'insights' => $this->generateInsights($revenueTrend, $projectsTrend, $employeeTrend),
        ];
    }

    protected function getRevenueTrend(int $organizationId, int $months): array
    {
        $data = [];
        $startDate = Carbon::now()->subMonths($months)->startOfMonth();
        
        for ($i = 0; $i < $months; $i++) {
            $monthStart = $startDate->copy()->addMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            
            $revenue = DB::table('contracts')
                ->where('organization_id', $organizationId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('total_amount') ?: 0;
            
            $data[] = [
                'month' => $monthStart->format('Y-m'),
                'value' => (float)$revenue,
            ];
        }
        
        return $data;
    }

    protected function getProjectsTrend(int $organizationId, int $months): array
    {
        $data = [];
        $startDate = Carbon::now()->subMonths($months)->startOfMonth();
        
        for ($i = 0; $i < $months; $i++) {
            $monthStart = $startDate->copy()->addMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            
            $count = DB::table('projects')
                ->where('organization_id', $organizationId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();
            
            $data[] = [
                'month' => $monthStart->format('Y-m'),
                'value' => $count,
            ];
        }
        
        return $data;
    }

    protected function getEmployeeProductivityTrend(int $organizationId, int $months): array
    {
        $data = [];
        $startDate = Carbon::now()->subMonths($months)->startOfMonth();
        
        for ($i = 0; $i < $months; $i++) {
            $monthStart = $startDate->copy()->addMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            
            $totalWorks = DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->whereBetween('completed_works.created_at', [$monthStart, $monthEnd])
                ->count();
            
            $activeEmployees = DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->whereBetween('completed_works.created_at', [$monthStart, $monthEnd])
                ->distinct('completed_works.user_id')
                ->count('completed_works.user_id');
            
            $productivity = $activeEmployees > 0 ? $totalWorks / $activeEmployees : 0;
            
            $data[] = [
                'month' => $monthStart->format('Y-m'),
                'value' => round($productivity, 2),
            ];
        }
        
        return $data;
    }

    protected function getTrendDirection(array $data): string
    {
        if (count($data) < 2) return 'stable';
        
        $first = reset($data)['value'];
        $last = end($data)['value'];
        
        if ($last > $first * 1.1) return 'up';
        if ($last < $first * 0.9) return 'down';
        return 'stable';
    }

    protected function calculateGrowthRate(array $data): float
    {
        if (count($data) < 2) return 0;
        
        $first = reset($data)['value'];
        $last = end($data)['value'];
        
        if ($first == 0) return 0;
        
        return round((($last - $first) / $first) * 100, 2);
    }

    protected function generateInsights(array $revenue, array $projects, array $productivity): array
    {
        $insights = [];
        
        $revenueDirection = $this->getTrendDirection($revenue);
        if ($revenueDirection === 'up') {
            $insights[] = "Выручка растет - положительная динамика";
        } elseif ($revenueDirection === 'down') {
            $insights[] = "Выручка снижается - требуется внимание";
        }
        
        $projectsDirection = $this->getTrendDirection($projects);
        if ($projectsDirection === 'up') {
            $insights[] = "Количество новых проектов увеличивается";
        }
        
        $productivityDirection = $this->getTrendDirection($productivity);
        if ($productivityDirection === 'down') {
            $insights[] = "Производительность снижается - возможна перегрузка";
        } elseif ($productivityDirection === 'up') {
            $insights[] = "Производительность растет - эффективность улучшается";
        }
        
        return $insights;
    }
}
