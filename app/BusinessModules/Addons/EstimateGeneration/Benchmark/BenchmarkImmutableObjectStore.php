<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

interface BenchmarkImmutableObjectStore extends BenchmarkPrivateObjectStore
{
    public function describe(string $path, int $maxBytes): BenchmarkPrivateObject;

    public function putImmutable(string $path, string $body, string $contentType): BenchmarkPrivateObject;

    public function removeCreated(BenchmarkPrivateObject $object): void;
}
