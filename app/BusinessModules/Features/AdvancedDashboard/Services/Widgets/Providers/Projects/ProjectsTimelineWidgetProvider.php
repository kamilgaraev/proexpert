<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProjectsTimelineWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::PROJECTS_TIMELINE;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $projects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->select('id', 'name', 'start_date', 'end_date', 'status', 'budget_amount')
            ->orderBy('start_date')
            ->get();

        $timeline = [];
        $now = Carbon::now();

        foreach ($projects as $project) {
            $startDate = Carbon::parse($project->start_date);
            $endDate = Carbon::parse($project->end_date);
            $totalDays = $startDate->diffInDays($endDate);
            $elapsedDays = $startDate->diffInDays(min($now, $endDate));
            $progress = $totalDays > 0 ? round(($elapsedDays / $totalDays) * 100, 2) : 0;

            $timeline[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'start_date' => $startDate->toIso8601String(),
                'end_date' => $endDate->toIso8601String(),
                'status' => $project->status,
                'budget' => (float)$project->budget_amount,
                'progress' => min(100, $progress),
                'is_overdue' => $now->greaterThan($endDate) && $project->status !== 'completed',
            ];
        }

        return [
            'timeline' => $timeline,
            'total_projects' => count($timeline),
        ];
    }
}

