<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use App\Models\SubscriptionPlan;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

use function trans_message;

class SubscriptionPlanStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::DASHBOARD_VIEW)
            && SystemAdminAccess::canAny([
                FilamentPermission::DASHBOARD_PLANS_VIEW,
                FilamentPermission::SUBSCRIPTION_PLANS_VIEW,
            ]);
    }

    protected function getStats(): array
    {
        $activePlans = SubscriptionPlan::where('is_active', true)->count();
        $totalPlans = SubscriptionPlan::count();

        return [
            Stat::make(
                trans_message('widgets.subscription_plan_stats.active_plans'),
                trans_message('widgets.subscription_plan_stats.active_plans_value', [
                    'active' => $activePlans,
                    'total' => $totalPlans,
                ]),
            )
                ->description(trans_message('widgets.subscription_plan_stats.active_plans_description'))
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('success'),
        ];
    }
}
