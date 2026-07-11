<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

interface MetricCalculator
{
    public function name(): string;

    /** @param array<string, mixed> $expected @param array<string, mixed> $prediction */
    public function calculate(array $expected, array $prediction, bool $technicalSuccess): MetricResultData;
}
