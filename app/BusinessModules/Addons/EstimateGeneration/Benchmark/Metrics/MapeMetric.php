<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

final readonly class MapeMetric implements MetricCalculator
{
    private const CAP = 10.0;

    public function __construct(private string $metricName, private string $key) {}

    public function name(): string
    {
        return $this->metricName;
    }

    public function calculate(array $expected, array $prediction, bool $technicalSuccess): MetricResultData
    {
        $actual = MetricInput::decimalMap($expected, $this->key);
        $predicted = $technicalSuccess ? MetricInput::decimalMap($prediction, $this->key) : [];
        $errors = [];
        $overflow = false;
        foreach ($actual as $id => $value) {
            $candidate = $predicted[$id] ?? 0.0;
            $error = $value === 0.0 ? ($candidate === 0.0 ? 0.0 : self::CAP) : abs($candidate - $value) / $value;
            $overflow = $overflow || $error > 1.0;
            $errors[] = min($error, self::CAP);
        }
        $raw = $errors === [] ? 0.0 : array_sum($errors) / count($errors);
        $score = 1.0 - min(1.0, $raw);

        return new MetricResultData($this->metricName, $score, $raw, array_sum($errors), count($errors), $overflow);
    }
}
