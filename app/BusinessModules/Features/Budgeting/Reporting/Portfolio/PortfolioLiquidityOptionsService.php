<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class PortfolioLiquidityOptionsService
{
    public function __construct(private ConnectionInterface $connection) {}

    public function options(ReportScope $scope): array
    {
        $today = CarbonImmutable::today();
        $projects = $this->connection->table('projects')
            ->whereIn('id', $scope->projectIds)
            ->where('status', 'active')
            ->where('is_archived', false)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (object $project): array => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
            ])
            ->values()
            ->all();
        $currencies = $this->connection
            ->table('budgeting_portfolio_liquidity_source_versions')
            ->where('organization_id', $scope->organizationId)
            ->whereNotNull('payload')
            ->selectRaw("UPPER(payload->>'currency') AS currency")
            ->whereRaw("payload->>'currency' ~ '^[A-Za-z]{3}$'")
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->map(static fn (mixed $currency): array => [
                'id' => (string) $currency,
                'name' => (string) $currency,
            ])
            ->values()
            ->all();

        return [
            'period' => [
                'default_as_of' => $today->format('Y-m-d'),
                'default_from' => $today->format('Y-m-d'),
                'default_to' => $today->addDays(30)->format('Y-m-d'),
                'max_horizon_days' => 366,
            ],
            'projects' => $projects,
            'currencies' => $currencies,
            'scenarios' => [
                ['id' => CashGapForecastContext::SCENARIO_BASE, 'name' => 'Базовый'],
                ['id' => CashGapForecastContext::SCENARIO_OPTIMISTIC, 'name' => 'Оптимистичный'],
                ['id' => CashGapForecastContext::SCENARIO_PESSIMISTIC, 'name' => 'Пессимистичный'],
                ['id' => CashGapForecastContext::SCENARIO_STRESS, 'name' => 'Стрессовый'],
                ['id' => CashGapForecastContext::SCENARIO_CUSTOM, 'name' => 'Пользовательский'],
            ],
        ];
    }
}
