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

class SessionStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_MONITOR);
    }

    protected function getStats(): array
    {
        $metrics = app(EstimateGenerationDashboardService::class)->metrics(DashboardFilters::fromArray($this->pageFilters));
        $currency = $metrics['currency'] ?? '';

        return [
            Stat::make(trans_message('estimate_generation.dashboard.sessions'), $metrics['sessions_total']),
            Stat::make(trans_message('estimate_generation.dashboard.apply_rate'), $this->percent($metrics['apply_rate'])),
            Stat::make(trans_message('estimate_generation.dashboard.average_duration'), $this->duration($metrics['average_duration_ms'])),
            Stat::make(trans_message('estimate_generation.dashboard.p95_duration'), $this->duration($metrics['p95_duration_ms'])),
            Stat::make(trans_message('estimate_generation.dashboard.documents'), $metrics['documents_total']),
            Stat::make(trans_message('estimate_generation.dashboard.review_rate'), $this->percent($metrics['review_rate'])),
            Stat::make(trans_message('estimate_generation.dashboard.total_cost'), $this->money($metrics['total_cost'], $currency)),
            Stat::make(trans_message('estimate_generation.dashboard.cost_per_successful'), $this->money($metrics['cost_per_successful_session'], $currency)),
            Stat::make(trans_message('estimate_generation.dashboard.cost_per_applied'), $this->money($metrics['cost_per_applied_session'], $currency)),
        ];
    }

    private function percent(mixed $value): string
    {
        return number_format((float) $value * 100, 1, ',', ' ').'%';
    }

    private function duration(mixed $milliseconds): string
    {
        return number_format((float) $milliseconds / 1000, 1, ',', ' ').' '.trans_message('estimate_generation.dashboard.seconds');
    }

    private function money(mixed $amount, mixed $currency): string
    {
        if ($amount === null || ! is_string($currency) || $currency === '') {
            return trans_message('estimate_generation.dashboard.unavailable');
        }

        return number_format((float) $amount, 2, ',', ' ').' '.$currency;
    }
}
