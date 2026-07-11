<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

interface BenchmarkPrivateObjectStore
{
    public function read(string $path, int $maxBytes): string;
}
