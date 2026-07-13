<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunDetailService;
use App\BusinessModules\Addons\EstimateGeneration\Operations\BenchmarkDispatchPolicy;
use App\Filament\Resources\EstimateGeneration\BenchmarkRunResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationBenchmarkResourceTest extends TestCase
{
    /** @return iterable<string, array{string, bool, bool, bool}> */
    public static function dispatchMatrix(): iterable
    {
        yield 'development' => ['development', false, false, true];
        yield 'regression' => ['regression', false, false, true];
        yield 'acceptance without confirmation' => ['acceptance', false, true, false];
        yield 'acceptance without QA permission' => ['acceptance', true, false, false];
        yield 'confirmed acceptance by QA' => ['acceptance', true, true, true];
    }

    #[DataProvider('dispatchMatrix')]
    public function test_dispatch_policy_enforces_dataset_kind_and_acceptance_confirmation(
        string $kind,
        bool $confirmed,
        bool $qaAllowed,
        bool $allowed,
    ): void {
        self::assertSame($allowed, BenchmarkDispatchPolicy::allows($kind, 'approved', $confirmed, $qaAllowed));
        self::assertFalse(BenchmarkDispatchPolicy::allows($kind, 'draft', $confirmed, $qaAllowed));
    }

    public function test_resource_query_and_view_are_bounded_and_privacy_safe(): void
    {
        self::assertSame([
            'id', 'uuid', 'organization_id', 'training_dataset_id', 'dataset_version',
            'pipeline_version', 'model_versions', 'normative_version', 'price_version',
            'metrics', 'duration_ms', 'cost_amount', 'currency', 'status', 'failure_code',
            'started_at', 'completed_at',
        ], BenchmarkRunResource::safeColumns());

        $source = $this->source(BenchmarkRunResource::class);
        self::assertStringContainsString('paginationPageOptions([25, 50, 100])', $source);
        self::assertStringContainsString("->defaultSort('started_at', 'desc')", $source);
        foreach (['case_results', 'error_summary', 'storage_path', 'raw_prompt', 'request_body', 'response_body', 'api_key', 'secret', 'credential'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($source));
        }
    }

    public function test_case_failure_presenter_is_bounded_and_discards_untrusted_fields(): void
    {
        $cases = array_fill(0, 120, [
            'case_id' => 'case-1',
            'status' => 'technical_failure',
            'failure_code' => 'provider_timeout',
            'prompt' => 'confidential estimate',
            'api_key' => 'secret',
            'request_body' => ['document' => 'private'],
            'stack_trace' => 'internal.php:10',
        ]);

        $failures = BenchmarkRunDetailService::safeCaseFailures($cases);
        $encoded = json_encode($failures, JSON_THROW_ON_ERROR);

        self::assertCount(100, $failures);
        self::assertSame(['case_id', 'status', 'failure_code'], array_keys($failures[0]));
        foreach (['prompt', 'api_key', 'secret', 'request', 'stack'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($encoded));
        }
    }

    public function test_run_action_delegates_to_application_service_without_direct_dispatch(): void
    {
        $source = $this->source(BenchmarkRunResource::class);

        self::assertStringContainsString('AdminBenchmarkDispatchService::class', $source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_BENCHMARKS', $source);
        self::assertStringNotContainsString('FilamentPermission::ESTIMATE_GENERATION_OPERATE', $source);
        self::assertStringContainsString('requiresConfirmation()', $source);
        foreach (['dispatch(', 'dispatchSync(', 'Bus::', 'Queue::', '->save()', '->update(['] as $mutation) {
            self::assertStringNotContainsString($mutation, $source);
        }
    }

    public function test_dispatch_service_contains_all_authoritative_guards(): void
    {
        $source = $this->source(\App\BusinessModules\Addons\EstimateGeneration\Operations\AdminBenchmarkDispatchService::class);

        foreach (['organizationId', 'idempotencyKey', 'actorId', 'confirmedAcceptance', 'canRunAcceptance', 'BenchmarkDispatchPolicy', 'recordAudit'] as $guard) {
            self::assertStringContainsString($guard, $source);
        }
    }

    public function test_queued_executor_is_bound_and_delegates_to_plan3_command_and_repository(): void
    {
        $executor = $this->source(\App\BusinessModules\Addons\EstimateGeneration\Operations\ConsoleStoredBenchmarkRunExecutor::class);
        $job = $this->source(\App\BusinessModules\Addons\EstimateGeneration\Jobs\RunEstimateGenerationBenchmarkJob::class);
        $provider = $this->source(\App\BusinessModules\Addons\EstimateGeneration\EstimateGenerationServiceProvider::class);

        self::assertStringContainsString("call('estimate-generation:benchmark'", $executor);
        self::assertStringContainsString('BenchmarkRunRepository', $executor);
        self::assertStringNotContainsString('->start(', $executor);
        self::assertStringContainsString('->complete(', $executor);
        self::assertStringContainsString('->fail(', $executor);
        self::assertStringContainsString('StoredBenchmarkRunExecutor $executor', $job);
        self::assertStringContainsString('ConsoleStoredBenchmarkRunExecutor::class', $provider);
    }

    /** @param class-string $class */
    private function source(string $class): string
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        self::assertIsString($source);

        return $source;
    }
}
