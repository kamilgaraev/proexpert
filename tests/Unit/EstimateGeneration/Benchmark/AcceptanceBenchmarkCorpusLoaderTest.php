<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptanceBenchmarkCorpusLoaderTest extends TestCase
{
    #[Test]
    public function private_org_scoped_manifest_and_objects_are_bounded_and_digest_verified(): void
    {
        [$store, $locator] = $this->store();
        $corpus = (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $locator);
        $case = $corpus->manifest->casesFor(BenchmarkDatasetType::Acceptance)[0];

        self::assertSame('acceptance:org-42:acceptance-private:v1', $corpus->executionReference);
        self::assertSame('PRIVATE PDF', $corpus->objects->read($case, 'input', 1024));
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
                (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $invalid);
                self::fail('Unsafe locator accepted.');
            } catch (BenchmarkContractException) {
                self::addToAssertionCount(1);
            }
        }

        $store->objects['org-42/estimate-generation/benchmarks/acceptance/case/input.pdf'] = 'tampered';
        $corpus = (new AcceptanceBenchmarkCorpusLoader($store))->load(42, $locator);
        $this->expectExceptionMessage('private_object_hash_mismatch');
        $corpus->objects->read($corpus->manifest->casesFor(BenchmarkDatasetType::Acceptance)[0], 'input', 1024);
    }

    /** @return array{object, string} */
    private function store(): array
    {
        $input = 'PRIVATE PDF';
        $expected = json_encode([
            'schema_version' => 1,
            'expected_model_schema_version' => 'benchmark-expected:v1',
            'expected' => [
                'sheet_type' => 'floor_plan', 'room_cells' => [], 'wall_cells' => [], 'opening_ids' => [],
                'areas' => [], 'quantities' => [], 'work_ids' => [], 'normative_rankings' => [], 'costs' => [],
                'applicable_item_ids' => [], 'evidence_ids_by_item' => [],
            ],
        ], JSON_THROW_ON_ERROR);
        $manifest = json_encode([
            'schema_version' => 1,
            'manifest_version' => 'acceptance-private:v1',
            'cases' => [[
                'id' => 'acceptance-vector-001', 'dataset' => 'acceptance', 'source_type' => 'vector_pdf',
                'input_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/input.pdf',
                'expected_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/expected.json',
                'input_sha256' => hash('sha256', $input), 'expected_sha256' => hash('sha256', $expected),
                'license' => 'private-approved-corpus', 'provenance' => 'private:approved', 'tags' => ['private'],
                'schema_version' => 1, 'expected_model_schema_version' => 'benchmark-expected:v1',
                'allowed_capabilities' => ['document_understanding'],
            ]],
        ], JSON_THROW_ON_ERROR);
        $store = new class(['org-42/estimate-generation/benchmarks/acceptance/manifest.json' => $manifest, 'org-42/estimate-generation/benchmarks/acceptance/case/input.pdf' => $input, 'org-42/estimate-generation/benchmarks/acceptance/case/expected.json' => $expected]) implements BenchmarkPrivateObjectStore
        {
            /** @param array<string, string> $objects */
            public function __construct(public array $objects) {}

            public function read(string $path, int $maxBytes): string
            {
                $value = $this->objects[$path] ?? throw new BenchmarkContractException('private_object_unavailable');
                if (strlen($value) > $maxBytes) {
                    throw new BenchmarkContractException('private_object_too_large');
                }

                return $value;
            }
        };

        return [$store, 's3://org-42/estimate-generation/benchmarks/acceptance/manifest.json'];
    }
}
