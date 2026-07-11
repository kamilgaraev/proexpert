<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

enum BenchmarkDatasetType: string
{
    case Development = 'development';
    case Regression = 'regression';
    case Acceptance = 'acceptance';
}
