<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use JsonException;

final readonly class AcceptanceBenchmarkCorpusLoader
{
    public function __construct(private BenchmarkPrivateObjectStore $store) {}

    public function load(int $organizationId, string $manifestLocator): BenchmarkCorpus
    {
        if ($organizationId < 1) {
            throw new BenchmarkContractException('acceptance_organization_invalid');
        }
        $prefix = 's3://org-'.$organizationId.'/estimate-generation/benchmarks/acceptance/';
        if (! str_starts_with($manifestLocator, $prefix) || ! str_ends_with($manifestLocator, '.json')
            || str_contains($manifestLocator, '..') || str_contains($manifestLocator, '?')) {
            throw new BenchmarkContractException('acceptance_manifest_locator_invalid');
        }
        $json = $this->store->read(substr($manifestLocator, 5), 2_000_000);
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BenchmarkContractException('acceptance_manifest_invalid');
        }
        if (! is_array($payload)) {
            throw new BenchmarkContractException('acceptance_manifest_invalid');
        }
        $manifest = BenchmarkManifest::fromArray($payload, __DIR__, hash('sha256', $json), false);
        $cases = $manifest->casesFor(BenchmarkDatasetType::Acceptance);
        if ($cases === [] || count($cases) !== $manifest->caseCount()) {
            throw new BenchmarkContractException('acceptance_dataset_invalid');
        }

        return new BenchmarkCorpus(
            $manifest,
            new PrivateBenchmarkObjectReader($this->store, $organizationId),
            'acceptance:org-'.$organizationId.':'.$manifest->manifestVersion,
        );
    }
}
