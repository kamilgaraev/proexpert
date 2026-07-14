<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use JsonException;

final readonly class AcceptanceBenchmarkCorpusLoader
{
    public function __construct(private BenchmarkPrivateObjectStore $store) {}

    public function load(int $organizationId, string $manifestLocator, ?BenchmarkManifest $publicManifest = null): BenchmarkCorpus
    {
        return $this->loadForDataset(BenchmarkDatasetType::Acceptance, $organizationId, $manifestLocator, null, $publicManifest);
    }

    public function loadForDataset(BenchmarkDatasetType $dataset, int $organizationId, string $manifestLocator, ?string $expectedSha256 = null, ?BenchmarkManifest $publicManifest = null): BenchmarkCorpus
    {
        if ($organizationId < 1) {
            throw new BenchmarkContractException('acceptance_organization_invalid');
        }
        $prefix = 's3://org-'.$organizationId.'/estimate-generation/benchmarks/'.$dataset->value.'/';
        if (! str_starts_with($manifestLocator, $prefix) || ! str_ends_with($manifestLocator, '.json')
            || str_contains($manifestLocator, '..') || str_contains($manifestLocator, '?')) {
            throw new BenchmarkContractException('acceptance_manifest_locator_invalid');
        }
        $json = $this->store->read(substr($manifestLocator, 5), 2_000_000);
        $actualSha256 = hash('sha256', $json);
        if ($expectedSha256 !== null && ! hash_equals($expectedSha256, $actualSha256)) {
            throw new BenchmarkContractException('manifest_integrity_failed');
        }
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BenchmarkContractException('acceptance_manifest_invalid');
        }
        if (! is_array($payload)) {
            throw new BenchmarkContractException('acceptance_manifest_invalid');
        }
        if ($dataset === BenchmarkDatasetType::Acceptance) {
            $payload = (new AcceptanceOwnerApprovalGuard($this->store))->approvedManifest($organizationId, $payload);
        }
        $manifest = BenchmarkManifest::fromArray($payload, __DIR__, $actualSha256, false);
        $cases = $manifest->casesFor($dataset);
        if ($cases === [] || count($cases) !== $manifest->caseCount()) {
            throw new BenchmarkContractException('acceptance_dataset_invalid');
        }
        if ($publicManifest !== null) {
            $this->assertGloballyDisjoint($publicManifest, $manifest);
        }
        $objects = new PrivateBenchmarkObjectReader($this->store, $organizationId);
        $this->preflight($cases, $objects);

        return new BenchmarkCorpus(
            $manifest,
            $objects,
            $dataset->value.':org-'.$organizationId.':'.$manifest->manifestVersion,
        );
    }

    private function assertGloballyDisjoint(BenchmarkManifest $public, BenchmarkManifest $acceptance): void
    {
        $ids = [];
        $locators = [];
        $digests = [];
        foreach ($public->cases() as $case) {
            $ids[$case->id] = true;
            $locators[$case->inputLocator] = true;
            $locators[$case->expectedLocator] = true;
            $digests[$case->inputSha256] = true;
            $digests[$case->expectedSha256] = true;
        }
        foreach ($acceptance->cases() as $case) {
            if (isset($ids[$case->id])) {
                throw new BenchmarkContractException('cross_manifest_case_id_collision');
            }
            if (isset($locators[$case->inputLocator]) || isset($locators[$case->expectedLocator])) {
                throw new BenchmarkContractException('cross_manifest_locator_collision');
            }
            if (isset($digests[$case->inputSha256]) || isset($digests[$case->expectedSha256])) {
                throw new BenchmarkContractException('cross_manifest_digest_ownership_collision');
            }
        }
    }

    /** @param list<BenchmarkCaseData> $cases */
    private function preflight(array $cases, BenchmarkObjectReader $objects): void
    {
        $descriptor = new BenchmarkFixtureDescriptorValidator;
        foreach ($cases as $case) {
            $input = $objects->read($case, 'input', 64_000_000);
            $expectedJson = $objects->read($case, 'expected', 4_000_000);
            $descriptor->validateBytes($input, $case->sourceType, $case->inputLocator, $case->allowedCapabilities);
            try {
                $expected = json_decode($expectedJson, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($expected)) {
                    throw new BenchmarkContractException('expected_contract_invalid');
                }
                BenchmarkExpectedContract::expected($expected, $case->expectedModelSchemaVersion);
            } catch (JsonException|BenchmarkContractException) {
                throw new BenchmarkContractException('expected_contract_invalid');
            }
        }
    }
}
