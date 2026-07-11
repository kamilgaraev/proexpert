<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

final readonly class ClassificationMetric implements MetricCalculator
{
    public function __construct(private string $metricName, private string $key) {}

    public function name(): string
    {
        return $this->metricName;
    }

    public function calculate(array $expected, array $prediction, bool $technicalSuccess): MetricResultData
    {
        $correct = $technicalSuccess && MetricInput::string($expected, $this->key) === MetricInput::string($prediction, $this->key);

        return new MetricResultData($this->metricName, $correct ? 1.0 : 0.0, $correct ? 1.0 : 0.0, $correct ? 1.0 : 0.0, 1);
    }
}
