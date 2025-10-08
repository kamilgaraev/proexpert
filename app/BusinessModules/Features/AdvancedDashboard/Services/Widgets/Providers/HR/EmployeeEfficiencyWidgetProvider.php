<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EmployeeEfficiencyWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::EMPLOYEE_EFFICIENCY;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $from = $request->from ?? Carbon::now()->subMonth();
        $to = $request->to ?? Carbon::now();

        $efficiency = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->join('users', 'completed_works.user_id', '=', 'users.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('COUNT(completed_works.id) as works_count'),
                DB::raw('SUM(completed_works.quantity * completed_works.price) as revenue'),
                DB::raw('AVG(CASE WHEN completed_works.quality_rating IS NOT NULL THEN completed_works.quality_rating ELSE 3 END) as avg_quality')
            )
            ->groupBy('users.id', 'users.name')
            ->get();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'efficiency' => $efficiency->map(fn($e) => [
                'user_id' => $e->user_id,
                'user_name' => $e->user_name,
                'productivity' => $e->works_count,
                'revenue_per_work' => $e->works_count > 0 ? round($e->revenue / $e->works_count, 2) : 0,
                'quality_rating' => round($e->avg_quality, 2),
                'efficiency_score' => round(($e->avg_quality / 5) * 100, 2),
            ])->toArray(),
        ];
    }
}

