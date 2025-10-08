<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class EmployeeWorkloadWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::EMPLOYEE_WORKLOAD;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $workload = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->join('users', 'completed_works.user_id', '=', 'users.id')
            ->where('projects.organization_id', $request->organizationId)
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('COUNT(completed_works.id) as tasks_count'),
                DB::raw('SUM(completed_works.quantity) as total_workload')
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('tasks_count')
            ->get();

        return [
            'workload' => $workload->map(fn($w) => [
                'user_id' => $w->user_id,
                'user_name' => $w->user_name,
                'tasks_count' => $w->tasks_count,
                'workload' => (float)$w->total_workload,
            ])->toArray(),
        ];
    }
}

