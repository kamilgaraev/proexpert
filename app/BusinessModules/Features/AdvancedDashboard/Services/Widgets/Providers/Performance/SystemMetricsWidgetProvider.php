<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SystemMetricsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::SYSTEM_METRICS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        // Для SaaS показываем метрики использования платформы конкретной организацией
        return $this->getOrganizationUsageMetrics($request->organizationId);
    }

    protected function getOrganizationUsageMetrics(int $organizationId): array
    {
        $monthAgo = Carbon::now()->subMonth();
        
        // Объем хранимых данных
        $dataVolume = [
            'total_projects' => DB::table('projects')
                ->where('organization_id', $organizationId)
                ->count(),
            'total_contracts' => DB::table('contracts')
                ->where('organization_id', $organizationId)
                ->count(),
            'total_works' => DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->count(),
            'total_materials' => DB::table('materials')
                ->where('organization_id', $organizationId)
                ->count(),
        ];

        // Активность за последний месяц
        $monthlyActivity = [
            'new_projects' => DB::table('projects')
                ->where('organization_id', $organizationId)
                ->where('created_at', '>=', $monthAgo)
                ->count(),
            'new_contracts' => DB::table('contracts')
                ->where('organization_id', $organizationId)
                ->where('created_at', '>=', $monthAgo)
                ->count(),
            'works_completed' => DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->where('completed_works.created_at', '>=', $monthAgo)
                ->count(),
        ];

        // Активные пользователи
        $activeUsers = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->where('completed_works.created_at', '>=', $monthAgo)
            ->distinct('completed_works.user_id')
            ->count('completed_works.user_id');

        // Использование модулей (на основе активности)
        $modulesUsage = [
            'projects' => DB::table('projects')
                ->where('organization_id', $organizationId)
                ->where('updated_at', '>=', $monthAgo)
                ->count() > 0,
            'contracts' => DB::table('contracts')
                ->where('organization_id', $organizationId)
                ->where('updated_at', '>=', $monthAgo)
                ->count() > 0,
            'materials' => DB::table('materials')
                ->where('organization_id', $organizationId)
                ->where('updated_at', '>=', $monthAgo)
                ->count() > 0,
        ];

        return [
            'usage_metrics' => [
                'organization_id' => $organizationId,
                'data_volume' => $dataVolume,
                'monthly_activity' => $monthlyActivity,
                'active_users_30d' => $activeUsers,
                'modules_usage' => $modulesUsage,
                'account_age_days' => $this->getAccountAgeDays($organizationId),
                'status' => 'healthy',
            ],
        ];
    }

    protected function getAccountAgeDays(int $organizationId): int
    {
        $created = DB::table('organizations')
            ->where('id', $organizationId)
            ->value('created_at');

        if (!$created) {
            return 0;
        }

        return Carbon::parse($created)->diffInDays(Carbon::now());
    }
}
