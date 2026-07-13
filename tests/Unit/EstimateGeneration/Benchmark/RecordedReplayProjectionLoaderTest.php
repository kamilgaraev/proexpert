<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedReplayProjectionLoader;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordedReplayProjectionLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/most-replay-projection-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $path) {
            unlink($path);
        }
        @rmdir($this->root);
    }

    #[Test]
    public function joins_recorded_dependencies_only_by_exact_case_and_input_hash(): void
    {
        $payload = $this->manifest();
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->root.'/recording.json', $json);

        $projection = (new RecordedReplayProjectionLoader($this->root))->load(
            $this->case(), 'recording.json', hash('sha256', $json),
        );

        self::assertSame($payload['envelopes']['vision_extraction']['locator'], $projection->recordedEnvelopeReferences['vision_extraction']);
        self::assertSame($payload['catalog']['locator'], $projection->benchmarkCatalogReference);
        self::assertSame($payload['recording_manifest_sha256'], $projection->recordingManifestSha256);
    }

    #[Test]
    public function rejects_oracle_fields_in_the_recording_manifest(): void
    {
        $payload = $this->manifest();
        $payload['expected_locator'] = 'expected.json';
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        file_put_contents($this->root.'/recording.json', $json);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recorded_projection_contract_invalid');
        (new RecordedReplayProjectionLoader($this->root))->load($this->case(), 'recording.json', hash('sha256', $json));
    }

    #[Test]
    public function rejects_a_manifest_for_another_source_hash(): void
    {
        $payload = $this->manifest();
        $payload['input_sha256'] = str_repeat('f', 64);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        file_put_contents($this->root.'/recording.json', $json);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recorded_projection_dependency_mismatch');
        (new RecordedReplayProjectionLoader($this->root))->load($this->case(), 'recording.json', hash('sha256', $json));
    }

    private function case(): BenchmarkPredictionCaseData
    {
        return new BenchmarkPredictionCaseData('replay-vision-001', BenchmarkDatasetType::Regression,
            BenchmarkSourceType::DimensionedSketch, 'input.svg', str_repeat('a', 64),
            ['production_replay'], ['vision'], [], []);
    }

    private function manifest(): array
    {
        return [
            'schema_version' => 'recorded-replay-projection:v1', 'case_id' => 'replay-vision-001',
            'input_sha256' => str_repeat('a', 64),
            'envelopes' => [
                'vision_extraction' => ['locator' => 'ports/vision.json', 'sha256' => str_repeat('b', 64)],
                'work_planning_model' => ['locator' => 'ports/work.json', 'sha256' => str_repeat('c', 64)],
                'normative_reranker' => ['locator' => 'ports/rerank.json', 'sha256' => str_repeat('d', 64)],
            ],
            'catalog' => ['locator' => 'catalog.json', 'sha256' => str_repeat('e', 64)],
            'recording_manifest_sha256' => str_repeat('1', 64),
        ];
    }
}
