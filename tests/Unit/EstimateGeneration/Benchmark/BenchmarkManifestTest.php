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
        self::assertSame([], $manifest->casesFor(BenchmarkDatasetType::Acceptance));
        self::assertSame(
            's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/manifest.json',
            $manifest->acceptanceManifestLocator,
        );

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
    public function manifest_load_does_not_open_or_validate_expected_bytes(): void
    {
        $root = sys_get_temp_dir().'/most-manifest-boundary-'.bin2hex(random_bytes(6));
        mkdir($root.'/case', 0700, true);
        copy($this->fixtureRoot.'/regression/dxf-house-001/input.dxf', $root.'/case/input.dxf');
        file_put_contents($root.'/case/expected.json', '{not-json');
        $payload = [
            'schema_version' => 1,
            'manifest_version' => 'boundary-test:v1',
            'cases' => [[
                'id' => 'boundary-case', 'dataset' => 'regression', 'source_type' => 'dxf',
                'input_locator' => 'case/input.dxf', 'expected_locator' => 'case/expected.json',
                'input_sha256' => hash_file('sha256', $root.'/case/input.dxf'),
                'expected_sha256' => hash_file('sha256', $root.'/case/expected.json'),
                'license' => 'CC0-1.0', 'provenance' => 'synthetic:boundary-test', 'tags' => ['boundary'],
                'schema_version' => 1, 'expected_model_schema_version' => 'benchmark-expected:v1',
                'allowed_capabilities' => ['document_understanding', 'geometry'],
            ]],
        ];
        file_put_contents($root.'/manifest.json', json_encode($payload, JSON_THROW_ON_ERROR));

        try {
            $manifest = BenchmarkManifest::fromFile($root.'/manifest.json', $root, false);
            self::assertSame('boundary-case', $manifest->cases()[0]->id);
        } finally {
            unlink($root.'/case/input.dxf');
            unlink($root.'/case/expected.json');
            unlink($root.'/manifest.json');
            rmdir($root.'/case');
            rmdir($root);
        }
    }

    #[Test]
    public function it_rejects_duplicate_content_across_datasets(): void
    {
        $manifest = $this->validInlineManifest();
        $manifest['cases'][1]['input_sha256'] = $manifest['cases'][0]['input_sha256'];

        $this->expectException(BenchmarkManifestException::class);
        $this->expectExceptionMessage('digest_ownership_collision');
        BenchmarkManifest::fromArray($manifest, $this->fixtureRoot);
    }

    #[Test]
    public function it_rejects_digest_reuse_across_input_and_expected_roles_in_both_directions(): void
    {
        $manifest = $this->validInlineManifest();
        $manifest['cases'][1]['expected_sha256'] = $manifest['cases'][0]['input_sha256'];

        try {
            BenchmarkManifest::fromArray($manifest, $this->fixtureRoot);
            self::fail('Cross-role digest reuse was accepted.');
        } catch (BenchmarkManifestException $exception) {
            self::assertSame('digest_ownership_collision', $exception->getMessage());
        }

        $manifest = $this->validInlineManifest();
        $manifest['cases'][1]['input_sha256'] = $manifest['cases'][0]['expected_sha256'];
        $this->expectExceptionMessage('digest_ownership_collision');
        BenchmarkManifest::fromArray($manifest, $this->fixtureRoot);
    }

    #[Test]
    public function it_rejects_same_case_input_and_expected_digest(): void
    {
        $manifest = $this->validInlineManifest();
        $manifest['cases'][0]['expected_sha256'] = $manifest['cases'][0]['input_sha256'];

        $this->expectExceptionMessage('digest_ownership_collision');
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
    public function it_rejects_unknown_source_enum_independently(): void
    {
        $manifest = $this->validInlineManifest();
        $manifest['cases'][0]['source_type'] = 'mystery';
        $this->expectExceptionMessage('source_type_invalid');
        BenchmarkManifest::fromArray($manifest, $this->fixtureRoot);
    }

    #[Test]
    public function it_rejects_missing_license_independently(): void
    {
        $manifest = $this->validInlineManifest();
        $manifest['cases'][0]['license'] = '';
        $this->expectExceptionMessage('license_invalid');
        BenchmarkManifest::fromArray($manifest, $this->fixtureRoot);
    }

    #[Test]
    public function acceptance_cases_are_never_resolved_in_tuning_mode(): void
    {
        $manifest = BenchmarkManifest::fromFile($this->fixtureRoot.'/manifest.json', $this->fixtureRoot);
        self::assertSame([], $manifest->casesFor(BenchmarkDatasetType::Acceptance));
        self::assertNotNull($manifest->acceptanceManifestLocator);
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
