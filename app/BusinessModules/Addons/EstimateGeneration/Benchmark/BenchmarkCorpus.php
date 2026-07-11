<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final readonly class BenchmarkCorpus
{
    public function __construct(
        public BenchmarkManifest $manifest,
        public BenchmarkObjectReader $objects,
        public string $executionReference,
    ) {}
}
