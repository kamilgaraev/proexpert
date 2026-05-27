<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use App\Services\Filament\SystemAdminDashboardService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

use function trans_message;

class PlatformGrowthStatsWidget extends BaseWidget
{
    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::DASHBOARD_VIEW)
            && SystemAdminAccess::canAny([
                FilamentPermission::USERS_VIEW,
                FilamentPermission::BLOG_ARTICLES_VIEW,
            ]);
    }

    protected function getStats(): array
    {
        $metrics = app(SystemAdminDashboardService::class)->overview();

        return [
            Stat::make(
                trans_message('widgets.platform_growth.new_users_7_days'),
                $metrics['users']['new_7_days'],
            )
                ->description(trans_message('widgets.platform_growth.new_users_7_days_description'))
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('success'),
            Stat::make(
                trans_message('widgets.platform_growth.new_users_30_days'),
                $metrics['users']['new_30_days'],
            )
                ->description(trans_message('widgets.platform_growth.new_users_30_days_description'))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),
            Stat::make(
                trans_message('widgets.platform_growth.blog_ready'),
                $metrics['blog']['published'] + $metrics['blog']['scheduled'],
            )
                ->description(trans_message('widgets.platform_growth.blog_ready_description', [
                    'published' => $metrics['blog']['published'],
                    'scheduled' => $metrics['blog']['scheduled'],
                ]))
                ->descriptionIcon('heroicon-m-newspaper')
                ->color('info'),
            Stat::make(
                trans_message('widgets.platform_growth.blog_drafts'),
                $metrics['blog']['draft'],
            )
                ->description(trans_message('widgets.platform_growth.blog_drafts_description'))
                ->descriptionIcon('heroicon-m-pencil-square')
                ->color($metrics['blog']['draft'] > 0 ? 'warning' : 'success'),
        ];
    }
}
