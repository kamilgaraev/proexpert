<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

interface BenchmarkReportOutputStore
{
    public function write(string $locator, string $contents): string;
}
