<?php

declare(strict_types=1);

namespace App\Filament\Widgets\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Monitoring\DashboardFilters;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationDashboardService;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class QueueHealthWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_MONITOR);
    }

    protected function getStats(): array
    {
        $metrics = app(EstimateGenerationDashboardService::class)->metrics(DashboardFilters::fromArray($this->pageFilters));

        return [
            Stat::make(trans_message('estimate_generation.dashboard.running_jobs'), $metrics['running_jobs']),
            Stat::make(trans_message('estimate_generation.dashboard.stale_jobs'), $metrics['stale_jobs'])
                ->color((int) $metrics['stale_jobs'] > 0 ? 'danger' : 'success'),
            Stat::make(
                trans_message('estimate_generation.dashboard.oldest_queue_age'),
                number_format((int) $metrics['oldest_queue_age_seconds'] / 60, 1, ',', ' ').' '.trans_message('estimate_generation.dashboard.minutes'),
            ),
        ];
    }
}
