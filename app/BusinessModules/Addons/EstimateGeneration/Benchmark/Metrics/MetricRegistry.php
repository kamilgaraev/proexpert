<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

use InvalidArgumentException;

final readonly class MetricRegistry
{
    /** @param list<MetricCalculator> $metrics */
    public function __construct(private array $metrics)
    {
        $names = array_map(static fn (MetricCalculator $metric): string => $metric->name(), $metrics);
        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('duplicate_metric');
        }
    }

    public static function standard(): self
    {
        return new self([
            new ClassificationMetric('sheet_classification_accuracy', 'sheet_type'),
            new SetIouMetric('room_iou', 'room_cells'),
            new SetIouMetric('wall_iou', 'wall_cells'),
            new SetF1Metric('opening_f1', 'opening_ids'),
            new MapeMetric('area_mape', 'areas'),
            new MapeMetric('quantity_mape', 'quantities'),
            new RecallMetric('work_recall', 'work_ids'),
            new TopKMetric('normative_top1', 1),
            new TopKMetric('normative_top3', 3),
            new MapeMetric('cost_mape', 'costs'),
            new TechnicalSuccessMetric,
            new EvidenceCoverageMetric,
        ]);
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $prediction @return array<string, MetricResultData> */
    public function calculate(array $expected, array $prediction, bool $technicalSuccess): array
    {
        $results = [];
        foreach ($this->metrics as $metric) {
            $result = $metric->calculate($expected, $prediction, $technicalSuccess);
            if (! $technicalSuccess) {
                $denominator = max(1, $result->denominator);
                $isMape = str_ends_with($metric->name(), '_mape');
                $result = new MetricResultData(
                    $metric->name(),
                    0.0,
                    $isMape ? 1.0 : 0.0,
                    $isMape ? (float) $denominator : 0.0,
                    $denominator,
                );
            }
            $results[$metric->name()] = $result;
        }

        return $results;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (MetricCalculator $metric): string => $metric->name(), $this->metrics);
    }
}
