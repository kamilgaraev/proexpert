<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final readonly class EstimateGenerationDashboardService
{
    public function __construct(
        private EstimateGenerationDashboardRepository $repository,
        private CacheRepository $cache,
    ) {}

    /** @return array<string, int|float|string|null> */
    public function metrics(DashboardFilters $filters): array
    {
        return $this->cache->remember($this->cacheKey('metrics', $filters), 30, function () use ($filters): array {
            $rows = $this->repository->metricRows($filters);

            return EstimateGenerationDashboardMetrics::fromRows($rows->sessions, $rows->usage, $rows->queue);
        });
    }

    public function costTrend(DashboardFilters $filters): CostTrendResult
    {
        return $this->cache->remember(
            $this->cacheKey('cost-trend', $filters),
            30,
            fn (): CostTrendResult => $this->repository->costTrend($filters),
        );
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
