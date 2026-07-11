<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifestException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkManifestTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
    }

    #[Test]
    public function repository_manifest_is_versioned_complete_hash_verified_and_disjoint(): void
    {
        $manifest = BenchmarkManifest::fromFile($this->fixtureRoot.'/manifest.json', $this->fixtureRoot);

        self::assertSame(1, $manifest->schemaVersion);
        self::assertSame(
            ['dimensioned_sketch', 'dwg', 'dxf', 'photo_plan', 'scanned_pdf', 'undimensioned_sketch', 'vector_pdf'],
            $manifest->sourceTypes(),
        );
        self::assertNotEmpty($manifest->casesFor(BenchmarkDatasetType::Development));
        self::assertNotEmpty($manifest->casesFor(BenchmarkDatasetType::Regression));
        self::assertNotEmpty($manifest->casesFor(BenchmarkDatasetType::Acceptance));

        foreach ([BenchmarkDatasetType::Development, BenchmarkDatasetType::Regression] as $dataset) {
            foreach ($manifest->casesFor($dataset) as $case) {
                self::assertFileExists($case->inputPath());
                self::assertFileExists($case->expectedPath());
                self::assertSame($case->inputSha256, hash_file('sha256', $case->inputPath()));
                self::assertSame($case->expectedSha256, hash_file('sha256', $case->expectedPath()));
                self::assertNotSame('', $case->license);
                self::assertNotSame('', $case->provenance);
            }
        }
    }

    #[Test]
    public function it_rejects_duplicate_content_across_datasets(): void
    {
        $manifest = $this->validInlineManifest();
        $manifest['cases'][1]['input_sha256'] = $manifest['cases'][0]['input_sha256'];

        $this->expectException(BenchmarkManifestException::class);
        $this->expectExceptionMessage('cross_dataset_input_digest_overlap');
        BenchmarkManifest::fromArray($manifest, $this->fixtureRoot);
    }

    #[Test]
    public function it_rejects_traversal_symlinks_unknown_enums_and_missing_license(): void
    {
        $manifest = $this->validInlineManifest();
        $manifest['cases'][0]['input_locator'] = '../client.pdf';
        $manifest['cases'][0]['source_type'] = 'mystery';
        $manifest['cases'][0]['license'] = '';

        $this->expectException(BenchmarkManifestException::class);
        BenchmarkManifest::fromArray($manifest, $this->fixtureRoot);
    }

    #[Test]
    public function acceptance_cases_are_never_resolved_in_tuning_mode(): void
    {
        $manifest = BenchmarkManifest::fromFile($this->fixtureRoot.'/manifest.json', $this->fixtureRoot);

        foreach ($manifest->casesFor(BenchmarkDatasetType::Acceptance) as $case) {
            self::assertStringStartsWith('s3://org-{organization_id}/', $case->inputLocator);
            self::assertStringStartsWith('s3://org-{organization_id}/', $case->expectedLocator);
            self::assertFalse($case->isLocallyReadable());
        }
    }

    /** @return array<string, mixed> */
    private function validInlineManifest(): array
    {
        return [
            'schema_version' => 1,
            'manifest_version' => 'inline:v1',
            'cases' => [
                $this->inlineCase('case-dev', 'development', str_repeat('a', 64), str_repeat('b', 64)),
                $this->inlineCase('case-reg', 'regression', str_repeat('c', 64), str_repeat('d', 64)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function inlineCase(string $id, string $dataset, string $inputHash, string $expectedHash): array
    {
        return [
            'id' => $id,
            'dataset' => $dataset,
            'source_type' => 'vector_pdf',
            'input_locator' => "development/$id/input.json",
            'expected_locator' => "development/$id/expected.json",
            'input_sha256' => $inputHash,
            'expected_sha256' => $expectedHash,
            'license' => 'CC0-1.0',
            'provenance' => 'synthetic:unit-test',
            'tags' => ['synthetic'],
            'schema_version' => 1,
            'expected_model_schema_version' => 'benchmark-expected:v1',
            'allowed_capabilities' => ['document_understanding'],
        ];
    }
}
