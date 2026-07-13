<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

interface EstimateGenerationDashboardRepository
{
    public function metricRows(DashboardFilters $filters): DashboardMetricRows;

    public function costTrend(DashboardFilters $filters): CostTrendResult;
}
