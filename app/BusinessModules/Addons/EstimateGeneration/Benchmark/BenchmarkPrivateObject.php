<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final readonly class BenchmarkPrivateObject
{
    public function __construct(
        public string $path,
        public string $body,
        public int $contentLength,
        public string $sha256,
        public ?string $etag,
        public ?string $versionId,
        public string $contentType,
        public bool $created = false,
    ) {}
}
