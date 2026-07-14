<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use JsonException;

final readonly class DatasetPrivateBenchmarkCorpusLoader
{
    public function __construct(private BenchmarkPrivateObjectStore $store) {}

    public function load(
        BenchmarkDatasetType $dataset,
        int $organizationId,
        string $basePrefix,
        string $manifestLocator,
        string $manifestSha256,
    ): BenchmarkCorpus {
        if (! in_array($dataset, [BenchmarkDatasetType::Development, BenchmarkDatasetType::Regression], true)) {
            throw new BenchmarkContractException('dataset_private_kind_invalid');
        }
        $reader = new DatasetPrivateBenchmarkObjectReader($this->store, $organizationId, $basePrefix);
        $manifestPrefix = 's3://'.str_replace('/objects/', '/manifest/', $basePrefix);
        if (! str_starts_with($manifestLocator, $manifestPrefix)
            || ! preg_match('/[a-f0-9]{64}\.json$/D', $manifestLocator)
            || str_contains($manifestLocator, '..') || str_contains($manifestLocator, '?')) {
            throw new BenchmarkContractException('dataset_private_manifest_locator_invalid');
        }
        $json = $this->store->read(substr($manifestLocator, 5), 2_000_000);
        if (! hash_equals($manifestSha256, hash('sha256', $json))) {
            throw new BenchmarkContractException('manifest_integrity_failed');
        }
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BenchmarkContractException('dataset_private_manifest_invalid');
        }
        if (! is_array($payload)) {
            throw new BenchmarkContractException('dataset_private_manifest_invalid');
        }
        $manifest = BenchmarkManifest::fromArray($payload, __DIR__, $manifestSha256, false);
        if ($manifest->casesFor($dataset) === []) {
            throw new BenchmarkContractException('dataset_private_manifest_empty');
        }

        return new BenchmarkCorpus($manifest, $reader, $dataset->value.':org-'.$organizationId.':'.$manifest->manifestVersion);
    }
}
