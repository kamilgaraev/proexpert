<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

final class TechnicalSuccessMetric implements MetricCalculator
{
    public function name(): string
    {
        return 'technical_success_rate';
    }

    public function calculate(array $expected, array $prediction, bool $technicalSuccess): MetricResultData
    {
        $value = $technicalSuccess ? 1.0 : 0.0;

        return new MetricResultData($this->name(), $value, $value, $value, 1);
    }
}
