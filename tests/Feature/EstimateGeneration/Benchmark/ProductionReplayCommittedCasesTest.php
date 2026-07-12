<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkAdapterRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunOptions;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProductionReplayBenchmarkAdapter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;

final class ProductionReplayCommittedCasesTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_diverse_committed_corpus_runs_through_registered_adapter_without_prediction_oracle(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $manifest = BenchmarkManifest::fromFile($root.'/production-replay-manifest.json', $root, false);
        $adapter = $this->app->make(BenchmarkAdapterRegistry::class)->get(ProductionReplayBenchmarkAdapter::ID);

        $report = $this->app->make(BenchmarkRunner::class)->run(
            $manifest,
            BenchmarkDatasetType::Regression,
            $adapter,
            new BenchmarkRunOptions('production-replay-cases:v1', 'recorded-ports:v1', 30_000, 0.0, 'strict-zero:v1', false),
            new LocalBenchmarkObjectReader,
            'repository-production-replay:v1',
        );

        self::assertSame(8, $report->attemptedCount);
        self::assertSame(8, $report->succeededCount);
        self::assertSame(0, $report->failedCount);
        self::assertSame(0, $report->skippedCount);
        self::assertSame(ProductionReplayBenchmarkAdapter::class, $adapter::class);
        self::assertSame(1.0, $report->metrics['technical_success_rate']['macro']);
        self::assertSame(1.0, $report->metrics['normative_top1']['macro']);
        self::assertSame(1.0, $report->metrics['cost_mape']['macro']);
        self::assertSame(1.0, $report->metrics['evidenced_applicable_items']['macro']);

        $second = $this->app->make(BenchmarkRunner::class)->run(
            $manifest,
            BenchmarkDatasetType::Regression,
            $adapter,
            new BenchmarkRunOptions('production-replay-cases:v1', 'recorded-ports:v1', 30_000, 0.0, 'strict-zero:v1', false),
            new LocalBenchmarkObjectReader,
            'repository-production-replay:v1',
        );
        self::assertSame($report->deterministicFingerprint, $second->deterministicFingerprint);
        self::assertSame($report->caseResults, $second->caseResults);

        foreach (glob($root.'/catalogs/*.json') ?: [] as $catalogPath) {
            $catalog = json_decode((string) file_get_contents($catalogPath), true, 32, JSON_THROW_ON_ERROR);
            self::assertCount(2, $catalog['candidates']);
            self::assertStringEndsWith('-alt', $catalog['candidates'][0]['candidate_id']);
            self::assertStringEndsWith('-primary', $catalog['candidates'][1]['candidate_id']);
            self::assertNotSame($catalog['prices'][0]['base_price'], $catalog['prices'][1]['base_price']);
        }

        foreach (['recordings', 'catalogs', 'projections'] as $directory) {
            foreach (glob($root.'/'.$directory.'/*.json') ?: [] as $artifact) {
                $payload = json_decode((string) file_get_contents($artifact), true, 64, JSON_THROW_ON_ERROR);
                $this->assertNoForbiddenKeys($payload, $artifact);
            }
        }

        $dxf = (string) file_get_contents($root.'/regression/replay-vector-wall-opening-001/input.dxf');
        foreach (['ROOMS', 'A-WALL', 'A-OPENING-DOOR', 'DIMENSIONS', '4000 mm', '3000 mm'] as $trace) {
            self::assertStringContainsString($trace, $dxf);
        }
        $ppm = (string) file_get_contents($root.'/regression/replay-vision-sketch-001/input.ppm');
        self::assertStringStartsWith("P6\n320 240\n255\n", $ppm);
        self::assertGreaterThan(200_000, strlen($ppm));
        self::assertGreaterThan(3_000, substr_count($ppm, "\0\0\0"));

        $manifestCases = array_column(json_decode((string) file_get_contents($root.'/production-replay-manifest.json'), true, 64, JSON_THROW_ON_ERROR)['cases'], null, 'id');
        foreach (glob($root.'/recordings/*-*.json') ?: [] as $recordingPath) {
            $recording = json_decode((string) file_get_contents($recordingPath), true, 64, JSON_THROW_ON_ERROR);
            $caseId = array_search($recording['source_sha256'], array_column($manifestCases, 'input_sha256', 'id'), true);
            self::assertIsString($caseId);
            self::assertSame($manifestCases[$caseId]['input_sha256'], $recording['source_sha256']);
            self::assertSame('contract_fixture', $recording['capture_kind']);
            self::assertSame('passed', $recording['privacy_result']);
            self::assertContains($recording['approval_ref'], ['plan3-task11-recorded-fixtures-v1', 'plan3-task11-corpus-v1']);
            if (! str_ends_with(basename($recordingPath), '-geometry.json')) {
                self::assertNotSame($recording['source_sha256'], $recording['input_dependency_sha256']);
            }
        }
        $visionRecording = json_decode((string) file_get_contents($root.'/recordings/vision-geometry.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('Комната', $visionRecording['payload']['elements'][0]['label']);
        self::assertArrayNotHasKey('labels', $visionRecording['payload']['elements'][0]);
    }

    private function assertNoForbiddenKeys(array $payload, string $path): void
    {
        $forbidden = ['expected', 'labels', 'metric', 'metrics', 'final_prediction', 'prediction', 'readiness',
            'price_total', 'prices_total', 'cost_total', 'total_price', 'total_cost'];
        $walk = function (array $value) use (&$walk, $forbidden, $path): void {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $normalized = strtolower($key);
                    self::assertFalse(in_array($normalized, $forbidden, true)
                        || str_starts_with($normalized, 'expected_') || str_starts_with($normalized, 'final_'),
                        $path.':'.$key);
                }
                if (is_array($item)) {
                    $walk($item);
                }
            }
        };
        $walk($payload);
    }
}
