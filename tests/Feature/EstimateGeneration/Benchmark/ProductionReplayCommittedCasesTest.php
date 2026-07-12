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
            self::assertContains(count($catalog['candidates']), [2, 3]);
            self::assertCount(count($catalog['candidates']), $catalog['resources']);
            self::assertCount(count($catalog['candidates']), $catalog['prices']);
            self::assertCount(count($catalog['candidates']), array_unique(array_column($catalog['resources'], 'candidate_id')));
            self::assertCount(count($catalog['prices']), array_unique(array_column($catalog['prices'], 'base_price')));
            foreach ($catalog['candidates'] as $candidate) {
                self::assertDoesNotMatchRegularExpression('/(?:^|[-_])(alt|primary)(?:$|[-_])/i', $candidate['candidate_id']);
                self::assertDoesNotMatchRegularExpression('/альтернатив|основн/i', $candidate['name']);
            }
            if ($catalog['approval_ref'] === 'plan3-task11-corpus-v2') {
                foreach ($catalog['prices'] as $price) {
                    self::assertSame('approved:fgiscs-regional-capture-2026-07', $price['snapshot_provenance']);
                    self::assertSame('plan3-task11-price-review', $price['snapshot_approval_ref']);
                }
            }
        }

        $selectedPositions = [];
        foreach (glob($root.'/recordings/*-reranker.json') ?: [] as $rerankerPath) {
            $reranker = json_decode((string) file_get_contents($rerankerPath), true, 32, JSON_THROW_ON_ERROR)['payload'];
            if (! str_contains(basename($rerankerPath), '-001-')) {
                continue;
            }
            $slug = substr(basename($rerankerPath), 0, -strlen('-reranker.json'));
            $catalog = json_decode((string) file_get_contents($root.'/catalogs/'.$slug.'.json'), true, 32, JSON_THROW_ON_ERROR);
            $candidateIds = array_column($catalog['candidates'], 'candidate_id');
            self::assertEqualsCanonicalizing($candidateIds, $reranker['ordering']);
            $selectedPositions[] = array_search($reranker['selected_candidate_id'], $candidateIds, true);
            $renamedAndReordered = array_reverse(array_map(
                static fn (string $id): string => $id === $reranker['selected_candidate_id'] ? $id : 'opaque-'.hash('sha256', $id),
                $candidateIds,
            ));
            self::assertContains($reranker['selected_candidate_id'], $renamedAndReordered);
        }
        sort($selectedPositions);
        self::assertSame([0, 0, 1, 2, 2], $selectedPositions);

        $builderSource = (string) file_get_contents($root.'/build-production-replay-corpus.php');
        self::assertStringNotContainsString('expected-authoring-plan3-task11.json', $builderSource);
        self::assertDoesNotMatchRegularExpression('/\$spec\s*\[\s*0\s*\]/', $builderSource);
        self::assertDoesNotMatchRegularExpression('/(?:^|[-_])(alt|primary)(?:$|[-_])/i', $builderSource);

        foreach (['recordings', 'catalogs', 'projections'] as $directory) {
            foreach (glob($root.'/'.$directory.'/*.json') ?: [] as $artifact) {
                if ($directory === 'recordings' && (str_ends_with($artifact, '-source-trace.json')
                    || str_ends_with($artifact, '-parser-proof.json') || str_ends_with($artifact, '/manifest.json')
                    || str_ends_with($artifact, '\\manifest.json'))) {
                    continue;
                }
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
            if (! isset($recording['capture_kind'])) {
                continue;
            }
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
