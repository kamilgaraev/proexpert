<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use App\Services\Filament\SystemAdminDashboardService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

use function trans_message;

class PlatformHealthStatsWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::DASHBOARD_VIEW)
            && SystemAdminAccess::canAny([
                FilamentPermission::ORGANIZATIONS_VIEW,
                FilamentPermission::BILLING_VIEW,
            ]);
    }

    protected function getStats(): array
    {
        $metrics = app(SystemAdminDashboardService::class)->overview();

        return [
            Stat::make(
                trans_message('widgets.platform_health.active_organizations'),
                $metrics['organizations']['active'],
            )
                ->description(trans_message('widgets.platform_health.active_organizations_description'))
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('success'),
            Stat::make(
                trans_message('widgets.platform_health.trial_organizations'),
                $metrics['organizations']['trial'],
            )
                ->description(trans_message('widgets.platform_health.trial_organizations_description'))
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('info'),
            Stat::make(
                trans_message('widgets.platform_health.paying_organizations'),
                $metrics['organizations']['paying'],
            )
                ->description(trans_message('widgets.platform_health.paying_organizations_description'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),
        ];
    }
}
