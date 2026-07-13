<?php

declare(strict_types=1);

namespace App\Filament\Widgets\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Monitoring\DashboardFilters;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationDashboardService;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class CostTrendWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = null;

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_MONITOR);
    }

    public function getHeading(): string
    {
        return trans_message('estimate_generation.dashboard.cost_trend');
    }

    public function getDescription(): ?string
    {
        $result = app(EstimateGenerationDashboardService::class)->costTrend(DashboardFilters::fromArray($this->pageFilters));

        return $result->truncated
            ? trans_message('estimate_generation.dashboard.currency_series_limited', ['count' => $result->omittedCurrencies])
            : null;
    }

    protected function getData(): array
    {
        $rows = app(EstimateGenerationDashboardService::class)->costTrend(DashboardFilters::fromArray($this->pageFilters))->rows;
        $labels = array_values(array_unique(array_map(static fn (array $row): string => substr($row['bucket'], 0, 10), $rows)));
        $byCurrency = [];
        foreach ($rows as $row) {
            $byCurrency[$row['currency']][substr($row['bucket'], 0, 10)] = $row['total_cost'];
        }
        $datasets = [];
        foreach ($byCurrency as $currency => $values) {
            $datasets[] = [
                'label' => trans_message('estimate_generation.dashboard.total_cost').' '.$currency,
                'data' => array_map(static fn (string $label): float => (float) ($values[$label] ?? 0), $labels),
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
