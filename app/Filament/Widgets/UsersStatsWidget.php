<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use App\Models\Organization;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

use function trans_message;

class UsersStatsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::DASHBOARD_VIEW)
            && SystemAdminAccess::canAny([
                FilamentPermission::DASHBOARD_USERS_VIEW,
                FilamentPermission::USERS_VIEW,
                FilamentPermission::ORGANIZATIONS_VIEW,
            ]);
    }

    protected function getStats(): array
    {
        $totalUsers = User::count();
        $newUsers = User::where('created_at', '>=', now()->startOfMonth())->count();

        $totalOrgs = Organization::count();

        return [
            Stat::make(trans_message('widgets.users_stats.total_users'), $totalUsers)
                ->description(trans_message('widgets.users_stats.total_description'))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->chart([7, 2, 10, 3, 15, 4, 17]),

            Stat::make(trans_message('widgets.users_stats.new_users'), $newUsers)
                ->description(trans_message('widgets.users_stats.new_description'))
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('success')
                ->chart([3, 5, 2, 8, 1, 9, 4]),

            Stat::make(trans_message('widgets.users_stats.organizations'), $totalOrgs)
                ->description(trans_message('widgets.users_stats.organizations_description'))
                ->descriptionIcon('heroicon-m-building-office')
                ->color('warning'),
        ];
    }
}
