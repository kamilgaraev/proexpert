<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionInterface;

final readonly class EstimateGenerationDashboardService
{
    public function __construct(
        private ConnectionInterface $database,
        private EstimateGenerationDashboardQueryFactory $queries,
        private CacheRepository $cache,
    ) {}

    /** @return array<string, int|float|string|null> */
    public function metrics(DashboardFilters $filters): array
    {
        return $this->cache->remember($this->cacheKey('metrics', $filters), 30, fn (): array => EstimateGenerationDashboardMetrics::fromRows(
            $this->one($this->queries->sessionMetrics($filters)),
            $this->one($this->queries->usageMetrics($filters)),
            $this->one($this->queries->queueHealth($filters)),
        ));
    }

    /** @return list<array{bucket: string, total_cost: float, currency: string, sessions: int}> */
    public function costTrend(DashboardFilters $filters): array
    {
        return $this->cache->remember($this->cacheKey('cost-trend', $filters), 30, function () use ($filters): array {
            $query = $this->queries->costTrend($filters);

            return array_map(static fn (object $row): array => [
                'bucket' => (string) ($row->bucket ?? ''),
                'total_cost' => (float) ($row->total_cost ?? 0),
                'currency' => (string) ($row->currency ?? ''),
                'sessions' => (int) ($row->sessions ?? 0),
            ], $this->database->select($query->sql, $query->bindings));
        });
    }

    /** @return array<string, mixed> */
    private function one(OperationalQuery $query): array
    {
        $row = $this->database->selectOne($query->sql, $query->bindings);

        return $row === null ? [] : get_object_vars($row);
    }

    private function cacheKey(string $scope, DashboardFilters $filters): string
    {
        return 'estimate-generation:dashboard:'.$scope.':'.hash('sha256', json_encode([
            $filters->from->toIso8601String(), $filters->until->toIso8601String(),
            $filters->organizationId, $filters->projectId, $filters->provider, $filters->model,
            $filters->stage, $filters->status, $filters->documentType, $filters->mode,
        ], JSON_THROW_ON_ERROR));
    }
}
