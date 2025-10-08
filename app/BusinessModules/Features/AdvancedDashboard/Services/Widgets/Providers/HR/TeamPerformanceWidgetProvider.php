<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TeamPerformanceWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::TEAM_PERFORMANCE;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $from = $request->from ?? Carbon::now()->subMonth();
        $to = $request->to ?? Carbon::now();

        $totalWorks = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->count();

        $totalRevenue = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->sum(DB::raw('completed_works.quantity * completed_works.price'));

        $teamMembers = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->distinct('completed_works.user_id')
            ->count('completed_works.user_id');

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'team_performance' => [
                'total_works_completed' => $totalWorks,
                'total_revenue' => (float)$totalRevenue,
                'team_members_count' => $teamMembers,
                'avg_works_per_member' => $teamMembers > 0 ? round($totalWorks / $teamMembers, 2) : 0,
                'avg_revenue_per_member' => $teamMembers > 0 ? round($totalRevenue / $teamMembers, 2) : 0,
            ],
        ];
    }
}

