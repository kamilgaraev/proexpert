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
        if (!DB::getSchemaBuilder()->hasTable('notifications')) {
            return ['notifications' => []];
        }

        $notifications = DB::table('notifications')
            ->where('notifiable_type', 'App\\Models\\User')
            ->limit(20)
            ->orderByDesc('created_at')
            ->get();

        return [
            'notifications' => $notifications->map(fn($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => json_decode($n->data, true),
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ])->toArray(),
        ];
    }
}

