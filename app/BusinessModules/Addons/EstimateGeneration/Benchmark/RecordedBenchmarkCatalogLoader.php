<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use InvalidArgumentException;
use JsonException;

final readonly class RecordedBenchmarkCatalogLoader
{
    public function __construct(private string $fixtureRoot) {}

    public function load(BenchmarkPredictionCaseData $case): RecordedBenchmarkCatalogData
    {
        if ($case->benchmarkCatalogReference === null || $case->benchmarkCatalogSha256 === null) {
            throw new InvalidArgumentException('recorded_catalog_projection_incomplete');
        }
        $root = realpath($this->fixtureRoot);
        $candidate = $this->fixtureRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $case->benchmarkCatalogReference);
        $path = realpath($candidate);
        if ($root === false || $path === false || is_link($candidate) || ! $this->within($path, $root)) {
            throw new InvalidArgumentException('recorded_catalog_path_invalid');
        }
        $size = @filesize($path);
        if (! is_int($size) || $size < 2 || $size > 2_000_000
            || ! hash_equals($case->benchmarkCatalogSha256, (string) hash_file('sha256', $path))) {
            throw new InvalidArgumentException('recorded_catalog_integrity_failed');
        }
        try {
            $payload = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('recorded_catalog_json_invalid');
        }

        return is_array($payload)
            ? RecordedBenchmarkCatalogData::fromArray($payload)
            : throw new InvalidArgumentException('recorded_catalog_contract_invalid');
    }

    private function within(string $path, string $root): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';

        return str_starts_with(str_replace('\\', '/', $path), $root);
    }
}
