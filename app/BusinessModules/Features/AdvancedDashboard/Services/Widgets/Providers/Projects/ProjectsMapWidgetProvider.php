<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ProjectsMapWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::PROJECTS_MAP;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $projects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->whereNotNull('address')
            ->select('id', 'name', 'address', 'status', 'budget_amount')
            ->get();

        $locations = [];

        foreach ($projects as $project) {
            $locations[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'address' => $project->address,
                'status' => $project->status,
                'budget' => (float)$project->budget_amount,
            ];
        }

        return [
            'total_locations' => count($locations),
            'locations' => $locations,
            'map_center' => [
                'lat' => 55.7558,
                'lng' => 37.6173,
            ],
        ];
    }
}

