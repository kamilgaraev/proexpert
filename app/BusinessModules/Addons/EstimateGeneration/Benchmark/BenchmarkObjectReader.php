<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

interface BenchmarkObjectReader
{
    public function read(BenchmarkCaseData|BenchmarkPredictionCaseData $case, string $role, int $maxBytes): string;
}
