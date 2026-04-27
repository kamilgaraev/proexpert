<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use App\Models\SystemAdmin;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class NotificationDeliveryStatsWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        $user = Auth::guard('system_admin')->user();

        return $user instanceof SystemAdmin
            && (
                $user->hasSystemPermission('system_admin.notifications.delivery_log.view')
                || $user->hasSystemPermission('system_admin.notifications.analytics.view')
            );
    }

    protected function getStats(): array
    {
        $today = now()->startOfDay();

        $totalToday = Notification::query()
            ->where('created_at', '>=', $today)
            ->count();

        $failedToday = NotificationAnalytics::query()
            ->where('status', 'failed')
            ->where('created_at', '>=', $today)
            ->count();

        $deliveredToday = NotificationAnalytics::query()
            ->whereIn('status', ['sent', 'delivered', 'opened', 'clicked'])
            ->where('created_at', '>=', $today)
            ->count();

        return [
            Stat::make('Создано сегодня', $totalToday)
                ->description('Новые уведомления за день')
                ->descriptionIcon('heroicon-m-bell')
                ->color('primary'),
            Stat::make('Успешные доставки', $deliveredToday)
                ->description('Отправлено или доставлено сегодня')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Ошибки доставки', $failedToday)
                ->description('Требуют проверки')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failedToday > 0 ? 'danger' : 'success'),
        ];
    }
}
