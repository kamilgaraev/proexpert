<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ProjectsStatusWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::PROJECTS_STATUS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $statusCounts = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->select('status', DB::raw('count(*) as count'), DB::raw('SUM(budget_amount) as total_budget'))
            ->groupBy('status')
            ->get();

        $total = $statusCounts->sum('count');
        $statusData = [];

        foreach ($statusCounts as $item) {
            $statusData[] = [
                'status' => $item->status,
                'count' => $item->count,
                'percentage' => $total > 0 ? round(($item->count / $total) * 100, 2) : 0,
                'total_budget' => (float)$item->total_budget,
            ];
        }

        return [
            'total_projects' => $total,
            'by_status' => $statusData,
        ];
    }
}

