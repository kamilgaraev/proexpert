<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ApiPerformanceWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::API_PERFORMANCE;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        // Для SaaS показываем метрики активности организации, а не глобальные метрики API
        return $this->getOrganizationActivityMetrics($request->organizationId);
    }

    protected function getOrganizationActivityMetrics(int $organizationId): array
    {
        $today = Carbon::today();
        $weekAgo = Carbon::now()->subDays(7);
        
        // Метрики активности за последние 24 часа
        $last24h = [
            'works_created' => DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->where('completed_works.created_at', '>=', Carbon::now()->subDay()->toDateTimeString())
                ->count(),
            'projects_updated' => DB::table('projects')
                ->where('organization_id', $organizationId)
                ->where('updated_at', '>=', Carbon::now()->subDay()->toDateTimeString())
                ->count(),
            'contracts_activity' => DB::table('contracts')
                ->where('organization_id', $organizationId)
                ->where('updated_at', '>=', Carbon::now()->subDay()->toDateTimeString())
                ->count(),
        ];

        // Метрики за последние 7 дней
        $last7days = [
            'works_created' => DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->where('completed_works.created_at', '>=', $weekAgo)
                ->count(),
            'active_users' => DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->where('completed_works.created_at', '>=', $weekAgo)
                ->distinct('completed_works.user_id')
                ->count('completed_works.user_id'),
        ];

        // Пиковые часы активности
        $hourlyActivity = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->where('completed_works.created_at', '>=', $weekAgo)
            ->select(DB::raw('EXTRACT(HOUR FROM completed_works.created_at) as hour'), DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('EXTRACT(HOUR FROM completed_works.created_at)'))
            ->orderByDesc('count')
            ->limit(3)
            ->get();

        $peakHours = $hourlyActivity->pluck('hour')->toArray();

        return [
            'activity_metrics' => [
                'organization_id' => $organizationId,
                'last_24h' => $last24h,
                'last_7_days' => $last7days,
                'peak_hours' => $peakHours,
                'avg_daily_activity' => $last7days['works_created'] > 0 
                    ? round($last7days['works_created'] / 7, 2) 
                    : 0,
                'status' => 'active',
            ],
        ];
    }
}
