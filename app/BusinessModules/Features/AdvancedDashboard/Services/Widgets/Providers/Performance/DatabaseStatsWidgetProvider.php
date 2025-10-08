<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class DatabaseStatsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::DATABASE_STATS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        // Для SaaS показываем статистику ТОЛЬКО по данным организации
        return $this->getOrganizationDataStats($request->organizationId);
    }

    protected function getOrganizationDataStats(int $organizationId): array
    {
        // Подсчитываем записи организации в основных таблицах
        $stats = [
            'projects' => [
                'count' => DB::table('projects')
                    ->where('organization_id', $organizationId)
                    ->count(),
                'active' => DB::table('projects')
                    ->where('organization_id', $organizationId)
                    ->whereIn('status', ['active', 'in_progress'])
                    ->count(),
            ],
            'contracts' => [
                'count' => DB::table('contracts')
                    ->where('organization_id', $organizationId)
                    ->count(),
                'active' => DB::table('contracts')
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active')
                    ->count(),
            ],
            'completed_works' => [
                'count' => DB::table('completed_works')
                    ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                    ->where('projects.organization_id', $organizationId)
                    ->count(),
                'last_30_days' => DB::table('completed_works')
                    ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                    ->where('projects.organization_id', $organizationId)
                    ->where('completed_works.created_at', '>=', now()->subDays(30))
                    ->count(),
            ],
            'users' => [
                'count' => DB::table('user_projects')
                    ->join('projects', 'user_projects.project_id', '=', 'projects.id')
                    ->where('projects.organization_id', $organizationId)
                    ->distinct('user_projects.user_id')
                    ->count('user_projects.user_id'),
            ],
            'materials' => [
                'count' => DB::table('materials')
                    ->where('organization_id', $organizationId)
                    ->count(),
            ],
        ];

        // Вычисляем рост за последние 30 дней
        $growth = $this->calculateGrowth($organizationId);

        return [
            'database_stats' => [
                'organization_id' => $organizationId,
                'records_by_entity' => $stats,
                'total_records' => array_sum([
                    $stats['projects']['count'],
                    $stats['contracts']['count'],
                    $stats['completed_works']['count'],
                    $stats['materials']['count'],
                ]),
                'growth_30_days' => $growth,
                'database_health' => 'ok',
            ],
        ];
    }

    protected function calculateGrowth(int $organizationId): array
    {
        $thirtyDaysAgo = now()->subDays(30);
        
        return [
            'new_projects' => DB::table('projects')
                ->where('organization_id', $organizationId)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count(),
            'new_contracts' => DB::table('contracts')
                ->where('organization_id', $organizationId)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count(),
            'new_works' => DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->where('completed_works.created_at', '>=', $thirtyDaysAgo)
                ->count(),
        ];
    }
}
