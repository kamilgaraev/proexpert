<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ResourceUtilizationWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::RESOURCE_UTILIZATION;
    }

    public function validateRequest(WidgetDataRequest $request): bool
    {
        return true;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        if (!$request->from || !$request->to) {
            $request = new WidgetDataRequest(
                widgetType: $request->widgetType,
                organizationId: $request->organizationId,
                userId: $request->userId,
                from: now()->startOfMonth(),
                to: now()->endOfMonth(),
                projectId: $request->projectId,
                contractId: $request->contractId,
                employeeId: $request->employeeId,
                filters: $request->filters,
                options: $request->options,
            );
        }

        $users = DB::table('users')
            ->join('user_projects', 'users.id', '=', 'user_projects.user_id')
            ->join('projects', 'user_projects.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->select('users.id', 'users.name')
            ->distinct()
            ->get();

        $utilization = [];
        $totalUtil = 0;

        foreach ($users as $user) {
            $worksCount = DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $request->organizationId)
                ->where('completed_works.user_id', $user->id)
                ->whereBetween('completed_works.created_at', [$request->from, $request->to])
                ->count();

            $activeProjects = DB::table('user_projects')
                ->join('projects', 'user_projects.project_id', '=', 'projects.id')
                ->where('user_projects.user_id', $user->id)
                ->where('projects.organization_id', $request->organizationId)
                ->where('projects.status', 'active')
                ->count();

            $utilizationRate = min(100, ($activeProjects * 25) + ($worksCount * 5));
            $totalUtil += $utilizationRate;

            $utilization[] = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'active_projects' => $activeProjects,
                'completed_works' => $worksCount,
                'utilization_rate' => round($utilizationRate, 2),
                'status' => $this->getUtilizationStatus($utilizationRate),
            ];
        }

        usort($utilization, fn($a, $b) => $b['utilization_rate'] <=> $a['utilization_rate']);

        return [
            'period' => [
                'from' => $request->from->toIso8601String(),
                'to' => $request->to->toIso8601String(),
            ],
            'utilization' => $utilization,
            'average_utilization' => count($utilization) > 0 ? round($totalUtil / count($utilization), 2) : 0,
        ];
    }

    protected function getUtilizationStatus(float $rate): string
    {
        if ($rate < 40) return 'underutilized';
        if ($rate <= 80) return 'optimal';
        return 'overutilized';
    }
}

