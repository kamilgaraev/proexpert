<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

use InvalidArgumentException;

final readonly class MetricResultData
{
    public function __construct(
        public string $name,
        public float $value,
        public float $rawValue,
        public float $numerator,
        public int $denominator,
        public bool $overflow = false,
    ) {
        foreach ([$value, $rawValue, $numerator] as $number) {
            if (! is_finite($number)) {
                throw new InvalidArgumentException('metric_not_finite');
            }
        }
        if ($denominator < 0 || $value < 0.0 || $value > 1.0 || $rawValue < 0.0) {
            throw new InvalidArgumentException('metric_out_of_bounds');
        }
    }
}
