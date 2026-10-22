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
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('overflowRecoveryModes')]
    public function test_paid_composer_supplementary_overflow_stops_before_auditor_and_refunds_the_operation_without_publishing(bool $recoveryError): void
    {
        $fixture = base_path('tests/Fixtures/EstimateGeneration/universal-replay/text-work-overflow-v1.json');
        $source = json_decode(file_get_contents($fixture), true, 64, JSON_THROW_ON_ERROR)['description'];
        $objects = new class($source) implements \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkObjectReader
        {
            public function __construct(private readonly string $source) {}

            public function read(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData|BenchmarkPredictionCaseData $case, string $role, int $maxBytes): string
            {
                if ($role !== 'input') {
                    throw new \LogicException('The failure scenario must not read an oracle.');
                }

                return $this->source;
            }
        };
        $before = (int) DB::table('estimate_generation_sessions')->max('id');
        $reserved = [];
        $this->app->resolving(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft::class, function () use ($before, $recoveryError, &$reserved): void {
            $job = \Illuminate\Support\Facades\Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class)->first();
            if ($job instanceof \App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob && $job->job === null) {
                $job->withFakeQueueInteractions();
                $session = DB::table('estimate_generation_sessions')->where('id', '>', $before)->sole();
                $operation = DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->sole();
                $organization = \App\Models\Organization::query()->findOrFail($session->organization_id);
                $reserved = ['balance' => $this->app->make(\App\Services\Credits\AICreditService::class)->balance($organization),
                    'reservation' => DB::table('ai_credit_reservations')->where('id', $operation->reservation_id)->sole(),
                    'ledger' => DB::table('ai_credit_ledger_entries')->where('organization_id', $session->organization_id)->orderBy('id')->get(),
                    'state_version' => $session->state_version];
                if ($recoveryError) {
                    DB::unprepared(<<<'SQL'
                        CREATE FUNCTION pg_temp.reject_overflow_refund() RETURNS trigger LANGUAGE plpgsql AS $$
                        BEGIN
                            RAISE EXCEPTION 'simulated_overflow_refund_failure' USING ERRCODE = '23514';
                        END;
                        $$;
                        SQL);
                    DB::unprepared('CREATE TRIGGER reject_overflow_refund BEFORE UPDATE ON ai_credit_reservations '
                        .'FOR EACH ROW WHEN (OLD.id = '.(int) $operation->reservation_id." AND OLD.status = 'reserved' AND NEW.status = 'cancelled') "
                        .'EXECUTE FUNCTION pg_temp.reject_overflow_refund()');
                }
            }
        });
        $adapter = new RecordedUniversalPipelineAdapter($this->app, $objects, $fixture, hash_file('sha256', $fixture));
        try {
            $adapter->run(new BenchmarkPredictionCaseData('supplementary-overflow', BenchmarkDatasetType::Regression,
                BenchmarkSourceType::TextDescription, 'work-ceiling.txt', hash('sha256', $source), ['current_repair'], []), 300000);
            self::fail('A paid composer expanded the approved work limit.');
        } catch (\InvalidArgumentException $exception) {
            if ($recoveryError) {
                $job = \Illuminate\Support\Facades\Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class)->first();
                $job->assertNotDeleted()->assertNotReleased()->assertNotFailed();
                self::assertSame('estimate_work_items_exceed_approved_plan', $exception->getPrevious()?->getMessage());
                self::assertSame('generating', DB::table('estimate_generation_sessions')->where('id', '>', $before)->sole()->status);
                self::assertSame($reserved['state_version'], DB::table('estimate_generation_sessions')->where('id', '>', $before)->sole()->state_version);
                self::assertContains(DB::table('estimate_generation_credit_operations')->where('session_id', '>', $before)->sole()->status, ['reserved', 'running']);
                self::assertEquals($reserved['reservation'], DB::table('ai_credit_reservations')->where('id', $reserved['reservation']->id)->sole());
                $organization = \App\Models\Organization::query()->findOrFail($reserved['reservation']->organization_id);
                self::assertEquals($reserved['balance'], $this->app->make(\App\Services\Credits\AICreditService::class)->balance($organization));
                self::assertEquals($reserved['ledger'], DB::table('ai_credit_ledger_entries')->where('organization_id', $organization->id)->orderBy('id')->get());
                DB::unprepared('DROP TRIGGER reject_overflow_refund ON ai_credit_reservations');
                $job->handle($this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft::class));
            }
            self::assertSame('estimate_review_required', DB::table('estimate_generation_sessions')->where('id', '>', $before)->sole()->status);
            self::assertInstanceOf(BenchmarkContractException::class, $exception);
            if (! $recoveryError) {
                self::assertStringStartsWith('recorded_replay_result_missing:', $exception->reason);
                self::assertNull($exception->getPrevious());
            }
        } finally {
            if ($recoveryError) {
                DB::unprepared('DROP TRIGGER IF EXISTS reject_overflow_refund ON ai_credit_reservations');
            }
        }
        $session = DB::table('estimate_generation_sessions')->where('id', '>', $before)->sole();
        self::assertSame('estimate_work_items_exceed_approved_plan', $session->failure_code);
        self::assertNull($session->last_error);
        self::assertNull($session->applied_estimate_id);
        $job = \Illuminate\Support\Facades\Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class)->first();
        $job->assertDeleted()->assertNotReleased()->assertNotFailed();
        $operation = DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->sole();
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(32, $plan['work_item_limit']);
        $role = DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)->where('role', 'estimate_composer')->sole();
        self::assertSame('completed', $role->status);
        $receipt = json_decode($role->result_payload, true, 64, JSON_THROW_ON_ERROR);
        self::assertCount($plan['work_item_limit'] + 1, $receipt['work_intents']);
        self::assertSame('failed', $operation->status);
        self::assertSame(0, (int) $operation->consumed_units_minor);
        self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_packages')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimates')->where('project_id', $session->project_id)->count());
        $usage = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->get();
        self::assertCount(1, $usage);
        self::assertSame('estimate_composition', $usage[0]->operation);
        self::assertGreaterThan(0, $usage[0]->cost_amount);
        self::assertSame(0, DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)->where('role', 'estimate_auditor')->count());
        self::assertSame('cancelled', DB::table('ai_credit_reservations')->where('id', $operation->reservation_id)->value('status'));
        $organization = \App\Models\Organization::query()->findOrFail($session->organization_id);
        $credits = $this->app->make(\App\Services\Credits\AICreditService::class);
        $balance = $credits->balance($organization);
        self::assertSame(0, $balance['reserved_minor']);
        self::assertSame(1000000, $balance['available_minor']);
        self::assertSame(1000000, $balance['total_minor']);
        $ledger = DB::table('ai_credit_ledger_entries')->where('organization_id', $session->organization_id)->orderBy('id')->get();
        $attempts = DB::table('estimate_generation_credit_attempts')->where('operation_id', $operation->operation_id)->get();
        self::assertCount(1, $attempts);
        self::assertNotNull($attempts[0]->usage_transferred_at);
        $models = $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class);
        $source = $models->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        self::assertNotEmpty($source->facts);
        self::assertSame(1, DB::table('estimate_generation_failures')->where('session_id', $session->id)
            ->where('code', 'estimate_work_items_exceed_approved_plan')->where('category', 'user_action_required')->count());
        $beforeReplay = DB::table('estimate_generation_sessions')->where('id', $session->id)->sole();
        $beforeOperation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operation->operation_id)->sole();
        $job->handle($this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft::class));
        self::assertEquals($beforeReplay, DB::table('estimate_generation_sessions')->where('id', $session->id)->sole());
        self::assertEquals($beforeOperation, DB::table('estimate_generation_credit_operations')->where('operation_id', $operation->operation_id)->sole());
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame($role->result_payload, DB::table('estimate_generation_ai_role_runs')->where('id', $role->id)->value('result_payload'));
        self::assertEquals($balance, $credits->balance($organization));
        self::assertEquals($ledger, DB::table('ai_credit_ledger_entries')->where('organization_id', $session->organization_id)->orderBy('id')->get());
        self::assertEquals($attempts, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operation->operation_id)->get());
        self::assertEquals($source, $models->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id));
        $input = json_decode($session->input_payload, true, 64, JSON_THROW_ON_ERROR);
        $handler = $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\HandleEstimateGenerationDraftFailure::class);
        foreach ([(int) $session->project_id, (int) $session->project_id + 1] as $projectId) {
            self::assertFalse($handler->handle(new \App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot(
                (int) $session->organization_id, $projectId, (int) $session->id, (int) $session->state_version - 1, 'generating',
                $input['generation_attempt_id'], (string) \Illuminate\Support\Str::uuid(), (string) \Illuminate\Support\Str::uuid(),
            ), new \InvalidArgumentException('estimate_work_items_exceed_approved_plan')));
        }
        self::assertEquals($beforeReplay, DB::table('estimate_generation_sessions')->where('id', $session->id)->sole());
        self::assertEquals($beforeOperation, DB::table('estimate_generation_credit_operations')->where('operation_id', $operation->operation_id)->sole());
        self::assertEquals($balance, $credits->balance($organization));
        $snapshot = $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionOperationalSnapshot::class)
            ->handle(\App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->findOrFail($session->id),
                ['estimate_generation.view', 'estimate_generation.generate']);
        self::assertSame('estimate_work_items_exceed_approved_plan', $snapshot->failureSummary['latest']['code']);
        self::assertSame('user_action_required', $snapshot->failureSummary['latest']['category']);
        self::assertFalse($snapshot->canApply);
    }

    public static function overflowRecoveryModes(): array
    {
        return ['first delivery' => [false], 'recovery error retries without deleting' => [true]];
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
