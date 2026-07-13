<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

use Illuminate\Database\ConnectionInterface;

final readonly class SqlEstimateGenerationDashboardRepository implements EstimateGenerationDashboardRepository
{
    public function __construct(
        private ConnectionInterface $database,
        private EstimateGenerationDashboardQueryFactory $queries,
    ) {}

    public function metricRows(DashboardFilters $filters): DashboardMetricRows
    {
        return new DashboardMetricRows(
            $this->one($this->queries->sessionMetrics($filters)),
            $this->one($this->queries->usageMetrics($filters)),
            $this->one($this->queries->queueHealth($filters)),
        );
    }

    public function costTrend(DashboardFilters $filters): CostTrendResult
    {
        $currencyRows = $this->all($this->queries->currencySelection($filters));
        $currencyCount = (int) ($currencyRows[0]['currencies_total'] ?? 0);
        $currencies = array_values(array_filter(array_map(
            static fn (array $row): ?string => is_string($row['currency'] ?? null) ? $row['currency'] : null,
            array_slice($currencyRows, 0, DashboardFilters::MAX_CURRENCY_SERIES),
        )));
        $rows = array_map(static fn (array $row): array => [
            'bucket' => (string) ($row['bucket'] ?? ''),
            'total_cost' => (float) ($row['total_cost'] ?? 0),
            'currency' => (string) ($row['currency'] ?? ''),
            'sessions' => (int) ($row['sessions'] ?? 0),
        ], $this->all($this->queries->costTrend($filters, $currencies)));
        $omittedCurrencies = max(0, $currencyCount - count($currencies));

        return new CostTrendResult($rows, $omittedCurrencies > 0, $omittedCurrencies);
    }

    /** @return array<string, mixed> */
    private function one(OperationalQuery $query): array
    {
        $row = $this->database->selectOne($query->sql, $query->bindings);

        return $row === null ? [] : get_object_vars($row);
    }

    /** @return list<array<string, mixed>> */
    private function all(OperationalQuery $query): array
    {
        return array_map(static fn (object $row): array => get_object_vars($row), $this->database->select(
            $query->sql,
            $query->bindings,
        ));
    }
}
