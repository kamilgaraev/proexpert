<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final readonly class ImmutableBenchmarkReportOutputStore implements ProductionImmutableBenchmarkReportOutputStore
{
    public function __construct(private BenchmarkImmutableObjectStore $objects) {}

    public function write(string $locator, string $contents): string
    {
        if (! str_starts_with($locator, 's3://')) {
            throw new BenchmarkCommandException('production_output_locator_invalid');
        }
        $object = $this->objects->putImmutable(substr($locator, 5), $contents, 'application/json');
        if ($object->versionId === null || $object->versionId === '') {
            throw new BenchmarkCommandException('production_output_version_missing');
        }

        return $object->path.'?versionId='.$object->versionId;
    }
}
