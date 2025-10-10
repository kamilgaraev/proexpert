<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\Services\GeocodingService;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectsMapWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::PROJECTS_MAP;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $projects = Project::with(['contracts' => function ($query) {
                $query->whereNull('parent_contract_id')
                      ->with('contractor');
            }])
            ->where('organization_id', $request->organizationId)
            ->whereNotNull('address')
            ->get()
            ->map(function ($project) {
                $mainContract = $project->contracts->first();
                
                return (object) [
                    'id' => $project->id,
                    'name' => $project->name,
                    'address' => $project->address,
                    'latitude' => $project->latitude,
                    'longitude' => $project->longitude,
                    'status' => $project->status,
                    'budget_amount' => $project->budget_amount,
                    'start_date' => $project->start_date?->format('Y-m-d'),
                    'end_date' => $project->end_date?->format('Y-m-d'),
                    'site_area_m2' => $project->site_area_m2,
                    'contractor_name' => $mainContract?->contractor?->name,
                ];
            });

        $locations = [];
        $coordinates = [];

        foreach ($projects as $project) {
            $completionPercentage = $this->calculateCompletion($project->id);

            $location = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'address' => $project->address,
                'status' => $project->status,
                'budget' => (float)$project->budget_amount,
                'coordinates' => ($project->latitude && $project->longitude) ? [
                    'lat' => (float) $project->latitude,
                    'lng' => (float) $project->longitude,
                ] : null,
                'contractor_name' => $project->contractor_name,
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'completion_percentage' => round($completionPercentage, 2),
                'total_area' => $project->site_area_m2 ? (float) $project->site_area_m2 : null,
            ];

            $locations[] = $location;

            if ($project->latitude && $project->longitude) {
                $coordinates[] = [
                    'lat' => (float) $project->latitude,
                    'lng' => (float) $project->longitude
                ];
            }
        }

        $geocodingService = new GeocodingService();
        $mapCenter = $geocodingService->calculateMapCenter($coordinates);
        $mapZoom = $geocodingService->calculateMapZoom($coordinates);

        return [
            'total_locations' => count($locations),
            'locations' => $locations,
            'map_center' => $mapCenter,
            'map_zoom' => $mapZoom,
        ];
    }

    private function calculateCompletion(int $projectId): float
    {
        $totalBudget = DB::table('contracts')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->sum('total_amount');

        if ($totalBudget == 0) {
            return 0;
        }

        $completedAmount = DB::table('completed_works')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->sum(DB::raw('quantity * price'));

        return ($completedAmount / $totalBudget) * 100;
    }
}

