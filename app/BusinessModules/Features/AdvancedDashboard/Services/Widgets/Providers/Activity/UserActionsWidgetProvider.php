<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UserActionsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::USER_ACTIONS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $limit = $request->getParam('limit', 100);
        $from = $request->from ?? Carbon::now()->subDays(7);
        $to = $request->to ?? Carbon::now();

        // Собираем действия пользователей из completed_works
        $userActions = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->join('users', 'completed_works.user_id', '=', 'users.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                'completed_works.created_at',
                'projects.name as project_name',
                'completed_works.work_type',
                DB::raw('completed_works.quantity * completed_works.price as value')
            )
            ->orderByDesc('completed_works.created_at')
            ->limit($limit)
            ->get();

        $actions = $userActions->map(fn($a) => [
            'user_id' => $a->user_id,
            'user_name' => $a->user_name,
            'action' => 'completed_work',
            'description' => "Выполнил работу в проекте {$a->project_name}",
            'timestamp' => $a->created_at,
            'value' => (float)$a->value,
        ])->toArray();

        // Статистика по пользователям
        $userStats = [];
        foreach ($userActions as $action) {
            $userId = $action->user_id;
            if (!isset($userStats[$userId])) {
                $userStats[$userId] = [
                    'user_id' => $userId,
                    'user_name' => $action->user_name,
                    'actions_count' => 0,
                    'total_value' => 0,
                ];
            }
            $userStats[$userId]['actions_count']++;
            $userStats[$userId]['total_value'] += (float)$action->value;
        }

        return [
            'actions' => $actions,
            'total_count' => count($actions),
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'top_users' => array_slice(
                array_values($userStats),
                0,
                10
            ),
        ];
    }
}
