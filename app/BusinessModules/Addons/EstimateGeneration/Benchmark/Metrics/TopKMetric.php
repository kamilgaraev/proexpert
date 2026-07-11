<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

use InvalidArgumentException;

final readonly class TopKMetric implements MetricCalculator
{
    public function __construct(private string $metricName, private int $k) {}

    public function name(): string
    {
        return $this->metricName;
    }

    public function calculate(array $expected, array $prediction, bool $technicalSuccess): MetricResultData
    {
        $groundTruth = $expected['normative_rankings'] ?? [];
        $rankings = $technicalSuccess ? ($prediction['normative_rankings'] ?? []) : [];
        if (! is_array($groundTruth) || ! is_array($rankings)) {
            throw new InvalidArgumentException('metric_input_invalid:normative_rankings');
        }
        $hits = 0;
        foreach ($groundTruth as $workId => $expectedRanking) {
            if (! is_string($workId) || ! is_array($expectedRanking) || ! isset($expectedRanking[0]) || ! is_string($expectedRanking[0])) {
                throw new InvalidArgumentException('metric_input_invalid:normative_rankings');
            }
            $predictionRanking = $rankings[$workId] ?? [];
            if (! is_array($predictionRanking)) {
                throw new InvalidArgumentException('metric_input_invalid:normative_rankings');
            }
            $hits += in_array($expectedRanking[0], array_slice($predictionRanking, 0, $this->k), true) ? 1 : 0;
        }
        $denominator = count($groundTruth);
        $value = $denominator === 0 ? 1.0 : $hits / $denominator;

        return new MetricResultData($this->metricName, $value, $value, (float) $hits, $denominator);
    }
}
