<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class TopPerformersWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::TOP_PERFORMERS;
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

        $limit = $request->getParam('limit', 10);

        $performers = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->join('users', 'completed_works.user_id', '=', 'users.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('COUNT(completed_works.id) as works_count'),
                DB::raw('SUM(completed_works.quantity * completed_works.price) as total_revenue')
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get();

        $avgRevenue = $performers->avg('total_revenue');

        return [
            'period' => [
                'from' => $request->from->toIso8601String(),
                'to' => $request->to->toIso8601String(),
            ],
            'top_performers' => $performers->map(fn($p) => [
                'user_id' => $p->user_id,
                'user_name' => $p->user_name,
                'works_count' => $p->works_count,
                'revenue' => (float)$p->total_revenue,
            ])->toArray(),
            'average_revenue' => (float)$avgRevenue,
        ];
    }
}

