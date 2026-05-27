<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use App\Services\Filament\SystemAdminDashboardService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

use function trans_message;

class PlatformRiskStatsWidget extends BaseWidget
{
    protected static ?int $sort = 7;

    public static function canView(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::DASHBOARD_VIEW)
            && SystemAdminAccess::canAny([
                FilamentPermission::SUPPORT_VIEW,
                FilamentPermission::PAYMENTS_VIEW,
                FilamentPermission::AUDIT_LOGS_VIEW,
            ]);
    }

    protected function getStats(): array
    {
        $metrics = app(SystemAdminDashboardService::class)->overview();

        return [
            Stat::make(
                trans_message('widgets.platform_risk.pending_support'),
                $metrics['support']['pending'],
            )
                ->description(trans_message('widgets.platform_risk.pending_support_description'))
                ->descriptionIcon('heroicon-m-lifebuoy')
                ->color($metrics['support']['pending'] > 0 ? 'warning' : 'success'),
            Stat::make(
                trans_message('widgets.platform_risk.urgent_support'),
                $metrics['support']['urgent'],
            )
                ->description(trans_message('widgets.platform_risk.urgent_support_description'))
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color($metrics['support']['urgent'] > 0 ? 'danger' : 'success'),
            Stat::make(
                trans_message('widgets.platform_risk.failed_payments'),
                $metrics['payments']['failed_30_days'],
            )
                ->description(trans_message('widgets.platform_risk.failed_payments_description'))
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($metrics['payments']['failed_30_days'] > 0 ? 'danger' : 'success'),
            Stat::make(
                trans_message('widgets.platform_risk.high_risk_audit'),
                $metrics['audit']['high_risk_24_hours'],
            )
                ->description(trans_message('widgets.platform_risk.high_risk_audit_description'))
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color($metrics['audit']['high_risk_24_hours'] > 0 ? 'warning' : 'success'),
        ];
    }
}
