<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class EmployeeKPIWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::EMPLOYEE_KPI;
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

        $userId = $request->employeeId ?? $request->userId;
        $user = User::find($userId);

        if (!$user) {
            return ['error' => 'User not found'];
        }

        $completedWorks = $this->getCompletedWorksCount($userId, $request);
        $workVolume = $this->getWorkVolume($userId, $request);
        $onTimeCompletion = $this->getOnTimeCompletionRate($userId, $request);
        $revenueGenerated = $this->getRevenueGenerated($userId, $request);

        $overallKPI = round(
            ($completedWorks * 0.2) +
            ($onTimeCompletion * 0.3) +
            ($workVolume * 0.2) +
            (min(100, $revenueGenerated / 10000) * 0.3),
            2
        );

        return [
            'user_id' => $userId,
            'user_name' => $user->name,
            'period' => [
                'from' => $request->from->toIso8601String(),
                'to' => $request->to->toIso8601String(),
            ],
            'metrics' => [
                'completed_works_count' => $completedWorks,
                'work_volume' => $workVolume,
                'on_time_completion_rate' => $onTimeCompletion,
                'revenue_generated' => $revenueGenerated,
            ],
            'overall_kpi' => $overallKPI,
            'performance_level' => $this->getPerformanceLevel($overallKPI),
        ];
    }

    protected function getCompletedWorksCount(int $userId, $request): int
    {
        return DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->where('completed_works.user_id', $userId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->count();
    }

    protected function getWorkVolume(int $userId, $request): float
    {
        $result = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->where('completed_works.user_id', $userId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->sum(DB::raw('completed_works.quantity * completed_works.price'));

        return $result ? (float)$result : 0.0;
    }

    protected function getOnTimeCompletionRate(int $userId, $request): float
    {
        $total = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->where('completed_works.user_id', $userId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->whereNotNull('completed_works.deadline')
            ->count();

        if ($total == 0) {
            return 100.0;
        }

        $onTime = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->where('completed_works.user_id', $userId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->whereNotNull('completed_works.deadline')
            ->whereRaw('completed_works.completed_at <= completed_works.deadline')
            ->count();

        return round(($onTime / $total) * 100, 2);
    }

    protected function getRevenueGenerated(int $userId, $request): float
    {
        $result = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->where('completed_works.user_id', $userId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->sum(DB::raw('completed_works.quantity * completed_works.price'));

        return $result ? (float)$result : 0.0;
    }

    protected function getPerformanceLevel(float $kpi): string
    {
        if ($kpi >= 80) return 'excellent';
        if ($kpi >= 60) return 'good';
        if ($kpi >= 40) return 'average';
        return 'needs_improvement';
    }
}

