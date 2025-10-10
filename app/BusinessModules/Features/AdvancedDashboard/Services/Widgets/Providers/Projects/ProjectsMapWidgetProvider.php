<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\Services\GeocodingService;
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
            ->leftJoin('contracts', function ($join) {
                $join->on('projects.id', '=', 'contracts.project_id')
                    ->whereNull('contracts.deleted_at')
                    ->whereNull('contracts.parent_contract_id');
            })
            ->leftJoin('contractors', 'contracts.contractor_id', '=', 'contractors.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereNotNull('projects.address')
            ->select(
                'projects.id',
                'projects.name',
                'projects.address',
                'projects.latitude',
                'projects.longitude',
                'projects.status',
                'projects.budget_amount',
                'projects.start_date',
                'projects.end_date',
                'projects.site_area_m2',
                DB::raw('contractors.name as contractor_name')
            )
            ->groupBy('projects.id')
            ->get();

        $locations = [];
        $coordinates = [];

        foreach ($projects as $project) {
            $lat = $project->latitude ? (float) $project->latitude : null;
            $lng = $project->longitude ? (float) $project->longitude : null;

            $completionPercentage = $this->calculateCompletion($project->id);

            $location = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'address' => $project->address,
                'status' => $project->status,
                'budget' => (float)$project->budget_amount,
                'coordinates' => ($lat && $lng) ? [
                    'lat' => $lat,
                    'lng' => $lng,
                ] : null,
                'contractor_name' => $project->contractor_name,
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'completion_percentage' => round($completionPercentage, 2),
                'total_area' => $project->site_area_m2 ? (float) $project->site_area_m2 : null,
            ];

            $locations[] = $location;

            if ($lat && $lng) {
                $coordinates[] = ['lat' => $lat, 'lng' => $lng];
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

