<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptanceBenchmarkCorpusLoaderTest extends TestCase
{
    #[Test]
    public function pending_missing_and_tampered_approval_fail_before_any_corpus_object_read(): void
    {
        foreach (['pending', 'missing', 'tampered'] as $scenario) {
            [$store, $locator] = $this->store();
            $manifestPath = 'org-42/estimate-generation/benchmarks/acceptance/manifest.json';
            $manifest = json_decode($store->objects[$manifestPath], true, 64, JSON_THROW_ON_ERROR);
            if ($scenario === 'pending') {
                $manifest['owner_approval']['status'] = 'pending_owner_approval';
                $manifest['owner_approval']['gate_execution_allowed'] = false;
                $store->objects[$manifestPath] = json_encode($manifest, JSON_THROW_ON_ERROR);
            } elseif ($scenario === 'missing') {
                unset($store->objects['org-42/estimate-generation/benchmarks/acceptance/owner-approval.json']);
            } else {
                $store->objects['org-42/estimate-generation/benchmarks/acceptance/owner-approval.json'] .= 'tampered';
            }

            try {
                (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $locator, $this->publicManifest());
                self::fail('Unapproved acceptance corpus was loaded.');
            } catch (BenchmarkContractException) {
                self::assertNotContains('org-42/estimate-generation/benchmarks/acceptance/case/input.ppm', $store->reads);
                self::assertNotContains('org-42/estimate-generation/benchmarks/acceptance/case/expected.json', $store->reads);
            }
        }
    }

    #[Test]
    public function private_org_scoped_manifest_and_objects_are_bounded_and_digest_verified(): void
    {
        [$store, $locator] = $this->store();
        $corpus = (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $locator, $this->publicManifest());
        $case = $corpus->manifest->casesFor(BenchmarkDatasetType::Acceptance)[0];

        self::assertSame('acceptance:org-42:acceptance-private:v1', $corpus->executionReference);
        self::assertSame("P3\n1 1\n255\n0 0 0\n", $corpus->objects->read($case, 'input', 1024));
        self::assertStringContainsString('expected_model_schema_version', $corpus->objects->read($case, 'expected', 4096));
    }

    #[Test]
    public function invalid_prefix_and_hash_are_rejected_without_cross_org_read(): void
    {
        [$store, $locator] = $this->store();
        foreach ([
            's3://org-41/estimate-generation/benchmarks/acceptance/manifest.json',
            'https://bucket/presigned?X-Amz-Signature=secret',
        ] as $invalid) {
            try {
                (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $invalid, $this->publicManifest());
                self::fail('Unsafe locator accepted.');
            } catch (BenchmarkContractException) {
                self::addToAssertionCount(1);
            }
        }

        $store->objects['org-42/estimate-generation/benchmarks/acceptance/case/input.ppm'] = 'tampered';
        $this->expectExceptionMessage('private_object_hash_mismatch');
        (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $locator, $this->publicManifest());
    }

    #[Test]
    public function acceptance_objects_must_be_globally_disjoint_from_public_corpus(): void
    {
        $public = $this->publicManifest();
        $publicInput = 'PUBLIC FIXTURE WITH REUSED DIGEST';
        $public = $this->publicManifest(hash('sha256', $publicInput));
        [$store, $locator] = $this->store($publicInput);

        $this->expectExceptionMessage('cross_manifest_digest_ownership_collision');
        (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $locator, $public);
    }

    #[Test]
    public function acceptance_input_digest_must_not_equal_public_expected_digest(): void
    {
        [$store, $locator] = $this->store();
        $digest = hash('sha256', "P3\n1 1\n255\n0 0 0\n");

        $this->expectExceptionMessage('cross_manifest_digest_ownership_collision');
        (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $locator, $this->publicManifest(null, $digest));
    }

    #[Test]
    public function acceptance_expected_digest_must_not_equal_public_input_digest(): void
    {
        [$store, $locator] = $this->store();
        $manifest = json_decode($store->objects['org-42/estimate-generation/benchmarks/acceptance/manifest.json'], true, 64, JSON_THROW_ON_ERROR);

        $this->expectExceptionMessage('cross_manifest_digest_ownership_collision');
        (new AcceptanceBenchmarkCorpusLoader($store))->load(
            42,
            $locator,
            $this->publicManifest($manifest['cases'][0]['expected_sha256']),
        );
    }

    #[Test]
    public function acceptance_case_id_must_not_collide_with_public_manifest(): void
    {
        [$store, $locator] = $this->store();
        $manifestPath = 'org-42/estimate-generation/benchmarks/acceptance/manifest.json';
        $manifest = json_decode($store->objects[$manifestPath], true, 64, JSON_THROW_ON_ERROR);
        $manifest['cases'][0]['id'] = 'public-boundary-001';
        $store->objects[$manifestPath] = json_encode($manifest, JSON_THROW_ON_ERROR);
        $this->approveStore($store, $manifestPath);

        $this->expectExceptionMessage('cross_manifest_case_id_collision');
        (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $locator, $this->publicManifest());
    }

    #[Test]
    public function acceptance_locator_must_not_collide_with_public_manifest(): void
    {
        [$store, $locator] = $this->store();
        $shared = 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/input.ppm';

        $this->expectExceptionMessage('cross_manifest_locator_collision');
        (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $locator, $this->publicManifest(null, null, $shared));
    }

    #[Test]
    public function all_cases_are_preflighted_before_corpus_is_returned_including_unsupported_cases(): void
    {
        [$store, $locator] = $this->store();
        $manifestPath = 'org-42/estimate-generation/benchmarks/acceptance/manifest.json';
        $manifest = json_decode($store->objects[$manifestPath], true, 64, JSON_THROW_ON_ERROR);
        $invalidExpected = '{"schema_version":1}';
        $manifest['cases'][] = array_merge($manifest['cases'][0], [
            'id' => 'acceptance-dwg-unsupported-002',
            'source_type' => 'dwg',
            'input_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/unsupported.dwg',
            'expected_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/unsupported.json',
            'input_sha256' => hash('sha256', "AC1027 SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1\n"),
            'expected_sha256' => hash('sha256', $invalidExpected),
            'tags' => ['private', 'unsupported_conversion'],
            'allowed_capabilities' => ['descriptor_validation', 'unsupported_conversion'],
        ]);
        $store->objects[$manifestPath] = json_encode($manifest, JSON_THROW_ON_ERROR);
        $store->objects['org-42/estimate-generation/benchmarks/acceptance/case/unsupported.dwg'] = "AC1027 SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1\n";
        $store->objects['org-42/estimate-generation/benchmarks/acceptance/case/unsupported.json'] = $invalidExpected;
        $this->approveStore($store, $manifestPath);

        $this->expectExceptionMessage('expected_contract_invalid');
        (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $locator, $this->publicManifest());
    }

    /** @return array{object, string} */
    private function store(string $input = "P3\n1 1\n255\n0 0 0\n"): array
    {
        $expected = json_encode([
            'schema_version' => 1,
            'expected_model_schema_version' => 'benchmark-expected:v1',
            'expected' => [
                'sheet_type' => 'floor_plan', 'room_cells' => [], 'wall_cells' => [], 'opening_ids' => [],
                'areas' => [], 'quantities' => [], 'work_ids' => [], 'normative_rankings' => [], 'costs' => [],
                'applicable_item_ids' => [], 'evidence_ids_by_item' => [],
            ],
        ], JSON_THROW_ON_ERROR);
        $manifestPayload = [
            'schema_version' => 1,
            'manifest_version' => 'acceptance-private:v1',
            'cases' => [[
                'id' => 'acceptance-photo-001', 'dataset' => 'acceptance', 'source_type' => 'photo_plan',
                'input_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/input.ppm',
                'expected_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/expected.json',
                'input_sha256' => hash('sha256', $input), 'expected_sha256' => hash('sha256', $expected),
                'license' => 'private-approved-corpus', 'provenance' => 'private:approved', 'tags' => ['private'],
                'schema_version' => 1, 'expected_model_schema_version' => 'benchmark-expected:v1',
                'allowed_capabilities' => ['document_understanding'],
            ]],
        ];
        $canonical = $manifestPayload;
        $sort = function (array &$value) use (&$sort): void {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $sort($canonical);
        $corpusDigest = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $approval = json_encode([
            'schema_version' => 1,
            'status' => 'approved',
            'approved' => true,
            'gate_execution_allowed' => true,
            'corpus_digest' => $corpusDigest,
            'provenance' => 'owner:fixture-approval:v1',
        ], JSON_THROW_ON_ERROR);
        $manifestPayload['owner_approval'] = [
            'status' => 'approved',
            'gate_execution_allowed' => true,
            'approval_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/owner-approval.json',
            'approval_sha256' => hash('sha256', $approval),
            'corpus_digest' => $corpusDigest,
            'provenance' => 'owner:fixture-approval:v1',
        ];
        $manifest = json_encode($manifestPayload, JSON_THROW_ON_ERROR);
        $store = new class(['org-42/estimate-generation/benchmarks/acceptance/manifest.json' => $manifest, 'org-42/estimate-generation/benchmarks/acceptance/owner-approval.json' => $approval, 'org-42/estimate-generation/benchmarks/acceptance/case/input.ppm' => $input, 'org-42/estimate-generation/benchmarks/acceptance/case/expected.json' => $expected]) implements BenchmarkPrivateObjectStore
        {
            public array $reads = [];

            /** @param array<string, string> $objects */
            public function __construct(public array $objects) {}

            public function read(string $path, int $maxBytes): string
            {
                $this->reads[] = $path;
                $value = $this->objects[$path] ?? throw new BenchmarkContractException('private_object_unavailable');
                if (strlen($value) > $maxBytes) {
                    throw new BenchmarkContractException('private_object_too_large');
                }

                return $value;
            }
        };

        return [$store, 's3://org-42/estimate-generation/benchmarks/acceptance/manifest.json'];
    }

    private function publicManifest(
        ?string $inputHash = null,
        ?string $expectedHash = null,
        string $inputLocator = 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/public/input.ppm',
    ): BenchmarkManifest {
        return BenchmarkManifest::fromArray([
            'schema_version' => 1,
            'manifest_version' => 'public-boundary:v1',
            'cases' => [[
                'id' => 'public-boundary-001', 'dataset' => 'acceptance', 'source_type' => 'photo_plan',
                'input_locator' => $inputLocator,
                'expected_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/public/expected.json',
                'input_sha256' => $inputHash ?? hash('sha256', 'PUBLIC INPUT'),
                'expected_sha256' => $expectedHash ?? hash('sha256', 'PUBLIC EXPECTED'),
                'license' => 'public-approved', 'provenance' => 'public:fixture', 'tags' => ['public'],
                'schema_version' => 1, 'expected_model_schema_version' => 'benchmark-expected:v1',
                'allowed_capabilities' => ['document_understanding'],
            ]],
        ], __DIR__, null, false);
    }

    private function approveStore(object $store, string $manifestPath): void
    {
        $manifest = json_decode($store->objects[$manifestPath], true, 64, JSON_THROW_ON_ERROR);
        unset($manifest['owner_approval']);
        $canonical = $manifest;
        $sort = function (array &$value) use (&$sort): void {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $sort($canonical);
        $corpusDigest = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $approval = json_encode([
            'schema_version' => 1, 'status' => 'approved', 'approved' => true,
            'gate_execution_allowed' => true, 'corpus_digest' => $corpusDigest,
            'provenance' => 'owner:fixture-approval:v1',
        ], JSON_THROW_ON_ERROR);
        $manifest['owner_approval'] = [
            'status' => 'approved', 'gate_execution_allowed' => true,
            'approval_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/owner-approval.json',
            'approval_sha256' => hash('sha256', $approval), 'corpus_digest' => $corpusDigest,
            'provenance' => 'owner:fixture-approval:v1',
        ];
        $store->objects[$manifestPath] = json_encode($manifest, JSON_THROW_ON_ERROR);
        $store->objects['org-42/estimate-generation/benchmarks/acceptance/owner-approval.json'] = $approval;
    }
}
