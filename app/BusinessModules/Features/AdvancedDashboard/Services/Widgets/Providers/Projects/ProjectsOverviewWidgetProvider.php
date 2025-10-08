<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ProjectsOverviewWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::PROJECTS_OVERVIEW;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $totalProjects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->count();

        $activeProjects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->where('status', 'active')
            ->count();

        $completedProjects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->where('status', 'completed')
            ->count();

        $totalBudget = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->sum('budget_amount');

        $totalArea = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->sum('site_area_m2');

        $avgBudget = $totalProjects > 0 ? $totalBudget / $totalProjects : 0;

        return [
            'total_projects' => $totalProjects,
            'active_projects' => $activeProjects,
            'completed_projects' => $completedProjects,
            'on_hold_projects' => $totalProjects - $activeProjects - $completedProjects,
            'total_budget' => (float)$totalBudget,
            'average_budget' => (float)$avgBudget,
            'total_area_m2' => (float)$totalArea,
            'completion_rate' => $totalProjects > 0 ? round(($completedProjects / $totalProjects) * 100, 2) : 0,
        ];
    }
}

