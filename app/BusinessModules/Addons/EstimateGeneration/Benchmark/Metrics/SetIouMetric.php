<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

final readonly class SetIouMetric implements MetricCalculator
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
        $union = array_unique([...$left, ...$right]);
        $intersection = array_intersect($left, $right);
        $denominator = count($union);
        $value = $denominator === 0 ? 1.0 : count($intersection) / $denominator;

        return new MetricResultData($this->metricName, $value, $value, (float) count($intersection), $denominator);
    }
}
