<?php

namespace App\Filament\Widgets;

use App\Models\SubscriptionPlan;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SubscriptionPlanStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $activePlans = SubscriptionPlan::where('is_active', true)->count();
        $totalPlans = SubscriptionPlan::count();
        
        // Тут можно добавить подсчет подписок по планам, если есть отношение
        // $popularPlan = ...

        return [
            Stat::make('Активные тарифы', $activePlans . ' из ' . $totalPlans)
                ->description('Доступные клиентам тарифные планы')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('success'),
        ];
    }
}
