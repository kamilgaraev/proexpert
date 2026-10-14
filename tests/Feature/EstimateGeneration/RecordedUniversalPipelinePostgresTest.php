<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkExpectedContract;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunOptions;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\InProcessBenchmarkCaseExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricRegistry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\RecordedUniversalPipelineAdapter;

final class RecordedUniversalPipelinePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_recorded_raw_http_runs_real_nine_stage_pipeline_authorization_money_and_unknown_result(): void
    {
        $root = base_path('tests/Fixtures/EstimateGeneration/universal-replay');
        $adapter = $this->adapter($root);
        $case = $this->case($root);
        $result = $adapter->run($case, 300000);
        self::assertSame('success', $result->status);
        $prediction = BenchmarkExpectedContract::prediction($result->prediction);
        // Oracle is read only after persisted prediction; it is absent from the adapter DTO.
        $expected = BenchmarkExpectedContract::expected(json_decode(file_get_contents($root.'/expected-partial-v1.json'), true, 32, JSON_THROW_ON_ERROR), 'benchmark-expected:v1');
        $metrics = MetricRegistry::standard()->calculate($expected, $prediction, true);
        self::assertSame(1.0, $metrics['work_recall']->value);
        self::assertSame(1.0, $metrics['unknown_numeric_values_preserved']->value);
        self::assertSame([], $prediction['quantities']);
        self::assertSame([], $prediction['costs']);
        self::assertSame('RUB', $result->currency);
        self::assertTrue(BigDecimal::of($result->costAmount)->isEqualTo('0.00945'));
        self::assertSame(2, $adapter->lastRun['provider_requests']);
        self::assertSame(2, $adapter->lastRun['requests_after_replay']);
        self::assertCount(2, $adapter->lastRun['usage']);
        self::assertSame(2, $adapter->lastRun['usage_after_replay']);
        $stages = array_column(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage::cases(), 'value');
        sort($stages);
        self::assertSame($stages, $adapter->lastRun['completed_stages']);
        self::assertCount(2, array_unique($adapter->lastRun['request_hashes']));
        self::assertSame(0, $adapter->lastRun['consumed_units_minor']);
        self::assertSame(1000000, $adapter->lastRun['available_minor']);
        self::assertLessThanOrEqual(3, count($adapter->lastRun['evaluation']['priority_questions']));
        self::assertNotEmpty($adapter->lastRun['evaluation']['priority_questions']);
        self::assertFalse($adapter->lastRun['evaluation']['accuracy_calibrated']);
        self::assertSame(1, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $adapter->lastRun['session_id'])->count());
    }

    public function test_changing_the_oracle_cannot_change_prediction_or_make_the_adapter_read_expected(): void
    {
        $root = base_path('tests/Fixtures/EstimateGeneration/universal-replay');
        $adapter = $this->adapter($root);
        $result = $adapter->run($this->case($root), 300000);
        $expected = BenchmarkExpectedContract::expected(json_decode(file_get_contents($root.'/expected-partial-v1.json'), true, 32, JSON_THROW_ON_ERROR), 'benchmark-expected:v1');
        $expected['work_ids'] = ['counterfactual-oracle-work'];
        self::assertSame(0.0, MetricRegistry::standard()->calculate($expected, $result->prediction, true)['work_recall']->value);
        self::assertContains('painting-from-description', $result->prediction['work_ids']);
        self::assertNotContains('counterfactual-oracle-work', $result->prediction['work_ids']);
        self::assertCount(0, array_filter(get_object_vars($this->case($root)), static fn ($value, $key): bool => str_contains($key, 'expected'), ARRAY_FILTER_USE_BOTH));
    }

    public function test_a_missing_or_modified_recording_fails_before_creating_a_session_or_usage(): void
    {
        $root = base_path('tests/Fixtures/EstimateGeneration/universal-replay');
        $before = DB::table('estimate_generation_sessions')->count();
        $usageBefore = DB::table('estimate_generation_ai_usage')->count();
        $adapter = new RecordedUniversalPipelineAdapter($this->app, new LocalBenchmarkObjectReader($root), $root.'/text-partial-v1.json', str_repeat('0', 64));
        try {
            $adapter->run($this->case($root), 300000);
            self::fail('A modified recording ran.');
        } catch (BenchmarkContractException $error) {
            self::assertSame('recorded_replay_recording_invalid', $error->reason);
        }
        self::assertSame($before, DB::table('estimate_generation_sessions')->count());
        self::assertSame($usageBefore, DB::table('estimate_generation_ai_usage')->count());
    }

    private function adapter(string $root): RecordedUniversalPipelineAdapter
    {
        return new RecordedUniversalPipelineAdapter($this->app, new LocalBenchmarkObjectReader($root), $root.'/text-partial-v1.json', hash_file('sha256', $root.'/text-partial-v1.json'));
    }

    public function test_actual_benchmark_runner_reads_separate_oracles_and_aggregates_real_replay_usage(): void
    {
        $root = base_path('tests/Fixtures/EstimateGeneration/universal-replay');
        $cases = [];
        foreach (['partial' => 'expected-partial-v1.json', 'canary' => 'counterfactual-oracle.json'] as $id => $oracle) {
            $input = $id === 'partial' ? 'input.txt' : 'input-canary.txt';
            $cases[] = ['id' => 'text-'.$id, 'dataset' => 'regression', 'source_type' => 'text_description',
                'input_locator' => $input, 'expected_locator' => $oracle,
                'input_sha256' => hash_file('sha256', $root.'/'.$input), 'expected_sha256' => hash_file('sha256', $root.'/'.$oracle),
                'license' => 'CC0-1.0', 'provenance' => 'synthetic:transport-integration', 'tags' => ['incomplete_project', 'current_repair'],
                'schema_version' => 1, 'expected_model_schema_version' => 'benchmark-expected:v1', 'allowed_capabilities' => ['test_runtime_replay']];
        }
        $manifest = BenchmarkManifest::fromArray(['schema_version' => 1, 'manifest_version' => 'recorded-universal-test:v1', 'cases' => $cases], $root, requireAllSourceTypes: false);
        $runner = new BenchmarkRunner(MetricRegistry::standard(), new InProcessBenchmarkCaseExecutor);
        $adapter = $this->adapter($root);
        $report = $runner->run($manifest, BenchmarkDatasetType::Regression, $adapter,
            new BenchmarkRunOptions('production-test:v1', 'gpt-6-luna-test:v1', 300000, 0), new LocalBenchmarkObjectReader($root));
        self::assertSame(2, $report->succeededCount);
        self::assertSame(0, $report->failedCount);
        self::assertSame('known', $report->costStatus);
        self::assertSame(0, $report->unknownCostAttempts);
        self::assertTrue(BigDecimal::of($report->costAmount)->isEqualTo('0.0189'));
        $byId = array_column($report->caseResults, null, 'case_id');
        self::assertSame(1.0, $byId['text-partial']['metrics']['work_recall']);
        self::assertSame(0.0, $byId['text-canary']['metrics']['work_recall']);
        self::assertSame(1.0, $report->metrics['unknown_numeric_values_preserved']['macro']);
        self::assertSame('synthetic:v1', $report->modelVersions['replay']);
    }

    private function case(string $root): BenchmarkPredictionCaseData
    {
        return new BenchmarkPredictionCaseData('text-partial-v1', BenchmarkDatasetType::Regression, BenchmarkSourceType::TextDescription,
            'input.txt', hash_file('sha256', $root.'/input.txt'), ['incomplete_project', 'current_repair'], []);
    }
}
