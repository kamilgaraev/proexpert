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

    public function test_two_committed_cases_run_through_registered_adapter_without_prediction_oracle(): void
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

        self::assertSame(2, $report->attemptedCount);
        self::assertSame(2, $report->succeededCount);
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
                $contents = strtolower((string) file_get_contents($artifact));
                self::assertDoesNotMatchRegularExpression('/"(?:expected|prediction|readiness|final_[^"]*|total_cost)"\s*:/', $contents);
            }
        }
    }
}
