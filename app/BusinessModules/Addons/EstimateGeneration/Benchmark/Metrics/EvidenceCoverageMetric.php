<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

use InvalidArgumentException;

final class EvidenceCoverageMetric implements MetricCalculator
{
    public function name(): string
    {
        return 'evidenced_applicable_items';
    }

    public function calculate(array $expected, array $prediction, bool $technicalSuccess): MetricResultData
    {
        $items = MetricInput::stringList($expected, 'applicable_item_ids');
        $evidence = $technicalSuccess ? ($prediction['evidence_ids_by_item'] ?? []) : [];
        if (! is_array($evidence)) {
            throw new InvalidArgumentException('metric_input_invalid:evidence_ids_by_item');
        }
        $covered = 0;
        foreach ($items as $item) {
            $ids = $evidence[$item] ?? [];
            $covered += is_array($ids) && $ids !== [] ? 1 : 0;
        }
        $denominator = count($items);
        $value = $denominator === 0 ? 1.0 : $covered / $denominator;

        return new MetricResultData($this->name(), $value, $value, (float) $covered, $denominator);
    }
}
