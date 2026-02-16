<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\Organization;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UsersStatsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $totalUsers = User::count();
        $newUsers = User::where('created_at', '>=', now()->startOfMonth())->count();
        
        $totalOrgs = Organization::count();
        $newOrgs = Organization::where('created_at', '>=', now()->startOfMonth())->count();

        return [
            Stat::make(__('widgets.users_stats.total_users'), $totalUsers)
                ->description(__('widgets.users_stats.total_description'))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->chart([7, 2, 10, 3, 15, 4, 17]),

            Stat::make(__('widgets.users_stats.new_users'), $newUsers)
                ->description(__('widgets.users_stats.new_description'))
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('success')
                ->chart([3, 5, 2, 8, 1, 9, 4]),
                
            Stat::make('Организации', $totalOrgs)
                ->description('Всего организаций в системе')
                ->descriptionIcon('heroicon-m-building-office')
                ->color('warning'),
        ];
    }
}
