<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPort;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortEnvelopeException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortEnvelopeLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordedPortEnvelopeLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/most-recorded-port-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/cases', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(glob($this->root.'/*/*') ?: []) as $path) {
            is_file($path) && unlink($path);
        }
        foreach (array_reverse(glob($this->root.'/*') ?: []) as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        is_dir($this->root) && rmdir($this->root);
        parent::tearDown();
    }

    #[Test]
    public function loader_verifies_immutable_descriptor_source_and_manifest_dependencies(): void
    {
        [$case, $benchmarkHash] = $this->caseAndHash();
        $path = $this->root.'/cases/vision.json';
        file_put_contents($path, $this->envelope($case->inputSha256, $case->inputSha256, $benchmarkHash, true));
        $this->writeManifest($case->id, hash_file('sha256', $path));

        $set = (new RecordedPortEnvelopeLoader($this->root, $this->root.'/manifest.json'))->load($case, $benchmarkHash);

        self::assertSame(RecordedPort::VisionExtraction, $set->require(RecordedPort::VisionExtraction)->port);
    }

    #[Test]
    public function projection_loader_resolves_only_declared_recorded_envelopes_without_expected_case_data(): void
    {
        [$case, $benchmarkHash] = $this->caseAndHash();
        $path = $this->root.'/cases/vision.json';
        file_put_contents($path, $this->envelope($case->inputSha256, $case->inputSha256, $benchmarkHash, true));
        $sha256 = hash_file('sha256', $path);
        $this->writeManifest($case->id, $sha256);
        $projection = new BenchmarkPredictionCaseData(
            $case->id,
            $case->dataset,
            $case->sourceType,
            $case->inputLocator,
            $case->inputSha256,
            $case->tags,
            $case->allowedCapabilities,
            ['vision_extraction' => 'cases/vision.json'],
            ['vision_extraction' => $sha256],
            $benchmarkHash,
        );

        $set = (new RecordedPortEnvelopeLoader($this->root, $this->root.'/manifest.json'))
            ->loadProjection($projection);

        self::assertSame(RecordedPort::VisionExtraction, $set->require(RecordedPort::VisionExtraction)->port);
    }

    #[Test]
    public function descriptor_tampering_is_rejected_before_payload_is_consumed(): void
    {
        [$case, $benchmarkHash] = $this->caseAndHash();
        $path = $this->root.'/cases/vision.json';
        file_put_contents($path, $this->envelope($case->inputSha256, $case->inputSha256, $benchmarkHash));
        $this->writeManifest($case->id, str_repeat('0', 64));

        $this->expectException(RecordedPortEnvelopeException::class);
        (new RecordedPortEnvelopeLoader($this->root, $this->root.'/manifest.json'))->load($case, $benchmarkHash);
    }

    #[Test]
    public function loader_rejects_payload_that_does_not_match_the_declared_port_contract(): void
    {
        [$case, $benchmarkHash] = $this->caseAndHash();
        $path = $this->root.'/cases/vision.json';
        file_put_contents($path, $this->envelope($case->inputSha256, $case->inputSha256, $benchmarkHash));
        $this->writeManifest($case->id, hash_file('sha256', $path));

        $this->expectException(RecordedPortEnvelopeException::class);
        $this->expectExceptionMessage('recorded_port_payload_invalid');

        (new RecordedPortEnvelopeLoader($this->root, $this->root.'/manifest.json'))->load($case, $benchmarkHash);
    }

    private function caseAndHash(): array
    {
        $fixtures = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $path = $fixtures.'/manifest.json';

        return [BenchmarkManifest::fromFile($path, $fixtures)->case('reg-dxf-001'), hash_file('sha256', $path)];
    }

    private function envelope(string $sourceHash, string $dependencyHash, string $manifestHash, bool $valid = false): string
    {
        $payload = $valid ? [
            'schema_version' => 1,
            'sheet_type' => 'floor_plan',
            'evidence' => [[
                'key' => 'page-1',
                'locator' => [
                    'coordinate_space' => 'normalized_source_v1',
                    'page_id' => 1,
                    'page_number' => 1,
                    'processing_unit_id' => 1,
                    'source_version' => 'sha256:'.$sourceHash,
                ],
            ]],
            'elements' => [],
            'scale_candidates' => [],
            'warnings' => ['scale_missing'],
        ] : ['schema_version' => 1, 'sheet_type' => 'floor_plan', 'elements' => []];
        ksort($payload, SORT_STRING);
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return json_encode([
            'schema_version' => 1, 'port' => 'vision_extraction', 'source_sha256' => $sourceHash,
            'input_dependency_sha256' => $dependencyHash, 'provider' => 'independent-provider',
            'model_version' => 'vision/model-v1', 'prompt_version' => 'vision-prompt:v1',
            'payload_schema_version' => 'vision-analysis:v1', 'payload' => $payload,
            'payload_sha256' => hash('sha256', $canonical), 'privacy_scanner' => 'most-fixture-privacy',
            'privacy_scanner_version' => '1.0.0', 'capture_kind' => 'contract_fixture',
            'privacy_result' => 'passed',
            'approval_kind' => 'maintainer_code_review',
            'approval_ref' => 'review:plan3-task11:independent-provider-output',
            'approved_at' => '2026-07-12T10:00:00Z', 'manifest_sha256' => $manifestHash,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function writeManifest(string $caseId, string $sha256): void
    {
        file_put_contents($this->root.'/manifest.json', json_encode([
            'schema_version' => 1,
            'fixtures' => [[
                'case_id' => $caseId, 'port' => 'vision_extraction',
                'locator' => 'cases/vision.json', 'sha256' => $sha256,
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
