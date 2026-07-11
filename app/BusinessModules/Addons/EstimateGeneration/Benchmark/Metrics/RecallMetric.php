<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

final readonly class RecallMetric implements MetricCalculator
{
    public function __construct(private string $metricName, private string $key) {}

    public function name(): string
    {
        return $this->metricName;
    }

    public function calculate(array $expected, array $prediction, bool $technicalSuccess): MetricResultData
    {
        $left = MetricInput::stringList($expected, $this->key);
        $right = $technicalSuccess ? MetricInput::stringList($prediction, $this->key) : [];
        $matches = count(array_intersect($left, $right));
        $denominator = count($left);
        $value = $denominator === 0 ? 1.0 : $matches / $denominator;

        return new MetricResultData($this->metricName, $value, $value, (float) $matches, $denominator);
    }
}
