<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ResourceDemandWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::RESOURCE_DEMAND;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $months = $request->getParam('months', 3);
        
        // Анализируем текущее использование ресурсов
        $currentUtilization = $this->getCurrentUtilization($request->organizationId);
        
        // Анализируем планируемые проекты
        $upcomingProjects = $this->getUpcomingProjects($request->organizationId);
        
        // Прогнозируем потребность
        $forecast = $this->forecastDemand($currentUtilization, $upcomingProjects, $months);
        
        return [
            'forecast_months' => $months,
            'current_utilization' => $currentUtilization,
            'upcoming_projects_count' => count($upcomingProjects),
            'forecast' => $forecast,
            'recommendations' => $this->generateRecommendations($forecast),
        ];
    }

    protected function getCurrentUtilization(int $organizationId): array
    {
        $totalEmployees = DB::table('user_projects')
            ->join('projects', 'user_projects.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->where('projects.status', 'active')
            ->distinct('user_projects.user_id')
            ->count('user_projects.user_id');

        $activeProjects = DB::table('projects')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->count();

        return [
            'total_employees' => $totalEmployees,
            'active_projects' => $activeProjects,
            'avg_employees_per_project' => $activeProjects > 0 ? round($totalEmployees / $activeProjects, 2) : 0,
        ];
    }

    protected function getUpcomingProjects(int $organizationId): array
    {
        $upcoming = DB::table('projects')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['planned', 'pending'])
            ->select('id', 'name', 'start_date', 'end_date')
            ->get();

        return $upcoming->toArray();
    }

    protected function forecastDemand(array $current, array $upcoming, int $months): array
    {
        $forecast = [];
        $baseLoad = $current['total_employees'];
        
        for ($i = 0; $i < $months; $i++) {
            $month = Carbon::now()->addMonths($i);
            
            // Базовая потребность + 10% рост на каждый новый месяц
            $demand = $baseLoad + ($i * ($baseLoad * 0.1));
            
            // Добавляем проекты, которые начинаются в этом месяце
            $projectsStarting = collect($upcoming)->filter(function($project) use ($month) {
                if (!$project->start_date) return false;
                $startDate = Carbon::parse($project->start_date);
                return $startDate->isSameMonth($month);
            })->count();
            
            $additionalDemand = $projectsStarting * ($current['avg_employees_per_project'] ?: 5);
            
            $forecast[] = [
                'month' => $month->format('Y-m'),
                'estimated_demand' => round($demand + $additionalDemand),
                'new_projects' => $projectsStarting,
                'shortage' => max(0, round(($demand + $additionalDemand) - $baseLoad)),
            ];
        }
        
        return $forecast;
    }

    protected function generateRecommendations(array $forecast): array
    {
        $recommendations = [];
        
        foreach ($forecast as $period) {
            if ($period['shortage'] > 5) {
                $recommendations[] = "Месяц {$period['month']}: необходимо {$period['shortage']} доп. сотрудников";
            }
        }
        
        if (empty($recommendations)) {
            $recommendations[] = "Текущих ресурсов достаточно на прогнозируемый период";
        }
        
        return $recommendations;
    }
}
