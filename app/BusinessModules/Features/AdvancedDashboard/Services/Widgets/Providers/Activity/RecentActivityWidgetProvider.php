<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class RecentActivityWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::RECENT_ACTIVITY;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $limit = $request->getParam('limit', 50);

        $activities = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->join('users', 'completed_works.user_id', '=', 'users.id')
            ->where('projects.organization_id', $request->organizationId)
            ->select(
                'completed_works.id',
                'completed_works.created_at',
                'users.name as user_name',
                'projects.name as project_name',
                DB::raw('completed_works.quantity * completed_works.price as value')
            )
            ->orderByDesc('completed_works.created_at')
            ->limit($limit)
            ->get();

        return [
            'activities' => $activities->map(fn($a) => [
                'id' => $a->id,
                'timestamp' => $a->created_at,
                'user' => $a->user_name,
                'project' => $a->project_name,
                'value' => (float)$a->value,
                'type' => 'completed_work',
            ])->toArray(),
        ];
    }
}

