<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class NotificationsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::NOTIFICATIONS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $limit = $request->getParam('limit', 20);
        
        if (!DB::getSchemaBuilder()->hasTable('notifications')) {
            return ['notifications' => [], 'unread_count' => 0];
        }

        // Получаем пользователей организации
        $userIds = DB::table('user_projects')
            ->join('projects', 'user_projects.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->distinct('user_projects.user_id')
            ->pluck('user_projects.user_id');

        if ($userIds->isEmpty()) {
            return ['notifications' => [], 'unread_count' => 0];
        }

        // Получаем уведомления пользователей организации
        $notifications = DB::table('notifications')
            ->where('notifiable_type', 'App\\Models\\User')
            ->whereIn('notifiable_id', $userIds)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $unreadCount = DB::table('notifications')
            ->where('notifiable_type', 'App\\Models\\User')
            ->whereIn('notifiable_id', $userIds)
            ->whereNull('read_at')
            ->count();

        return [
            'notifications' => $notifications->map(fn($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => json_decode($n->data, true),
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ])->toArray(),
            'unread_count' => $unreadCount,
            'total_count' => $notifications->count(),
        ];
    }
}
