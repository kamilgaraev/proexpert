<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunDetailService;
use App\BusinessModules\Addons\EstimateGeneration\Operations\BenchmarkDispatchPolicy;
use App\Filament\Resources\EstimateGeneration\BenchmarkRunResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationBenchmarkResourceTest extends TestCase
{
    #[Test]
    public function dataset_private_reader_resolves_only_relative_paths_inside_exact_tenant_prefix(): void
    {
        $store = new class implements \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore
        {
            public string $path = '';

            public function read(string $path, int $maxBytes): string
            {
                $this->path = $path;

                return 'payload';
            }
        };
        $reader = new \App\BusinessModules\Addons\EstimateGeneration\Benchmark\DatasetPrivateBenchmarkObjectReader(
            $store, 71, 'org-71/estimate-generation/benchmark-imports/sha256-abc/objects/',
        );
        $case = new \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData(
            'case-1', \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType::Regression,
            \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType::VectorPdf,
            'inputs/a.pdf', 'expected/a.json', hash('sha256', 'payload'), hash('sha256', 'payload'),
            'private', 'tenant-import', [], 1, 'model:v1', [], '',
        );
        self::assertSame('payload', $reader->read($case, 'input', 100));
        self::assertSame('org-71/estimate-generation/benchmark-imports/sha256-abc/objects/inputs/a.pdf', $store->path);
    }

    #[Test]
    public function dataset_private_reader_rejects_traversal_absolute_uri_and_foreign_prefix(): void
    {
        $store = new class implements \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore
        {
            public function read(string $path, int $maxBytes): string
            {
                self::fail('store must not be called');
            }
        };
        $reader = new \App\BusinessModules\Addons\EstimateGeneration\Benchmark\DatasetPrivateBenchmarkObjectReader(
            $store, 71, 'org-71/estimate-generation/benchmark-imports/sha256-abc/objects/',
        );
        foreach (['../secret', '/absolute/file', 's3://org-72/file', 'https://example.test/file'] as $locator) {
            try {
                $case = new \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData(
                    'case-1', \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType::Development,
                    \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType::VectorPdf,
                    $locator, 'expected/a.json', str_repeat('a', 64), str_repeat('b', 64),
                    'private', 'tenant-import', [], 1, 'model:v1', [], '',
                );
                $reader->read($case, 'input', 100);
                self::fail('unsafe locator accepted');
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function stored_execution_snapshot_is_the_only_queued_authority(): void
    {
        $root = dirname(__DIR__, 4);
        $job = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Jobs/RunEstimateGenerationBenchmarkJob.php');
        $executor = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Operations/ConsoleStoredBenchmarkRunExecutor.php');
        $migration = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000500_add_benchmark_execution_snapshot.php');

        self::assertIsString($job);
        self::assertStringContainsString('public readonly string $idempotencyKey', $job);
        self::assertStringNotContainsString('datasetType', $job);
        self::assertStringNotContainsString('manifestLocator', $job);
        self::assertIsString($executor);
        self::assertStringContainsString('execution_snapshot', $executor);
        self::assertStringContainsString('assertReportMatches', $executor);
        self::assertStringContainsString("where('organization_id'", $executor);
        self::assertIsString($migration);
        self::assertStringContainsString('execution_snapshot jsonb', $migration);
        self::assertStringContainsString('eg_benchmark_execution_snapshot_valid_v1', $migration);
        self::assertStringContainsString('execution_snapshot <> OLD.execution_snapshot', $migration);
        self::assertStringContainsString('estimate_generation_setting_snapshots', $migration);
        self::assertStringContainsString('settings_snapshot_hash', $migration);
        self::assertStringContainsString("settings_row.snapshot->'models'", $migration);
        self::assertStringContainsString("settings_row.snapshot->'limits'", $migration);
        $provider = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');
        $resource = file_get_contents($root.'/app/Filament/Resources/EstimateGeneration/BenchmarkRunResource.php');
        self::assertIsString($provider);
        self::assertStringContainsString('CurrentBaselineBenchmarkAdapter::class', $provider);
        self::assertIsString($resource);
        self::assertStringContainsString("->default('current-baseline')", $resource);
        self::assertStringNotContainsString("->default('production-replay')", $resource);
    }

    #[Test]
    public function dispatch_service_loads_effective_settings_and_rejects_caller_stale_identity(): void
    {
        $source = file_get_contents((new ReflectionClass(\App\BusinessModules\Addons\EstimateGeneration\Operations\AdminBenchmarkDispatchService::class))->getFileName());
        self::assertIsString($source);
        self::assertStringContainsString('snapshotForNewWork((int) $dataset->organization_id)', $source);
        self::assertStringContainsString('benchmark_settings_snapshot_stale', $source);
        self::assertStringContainsString("'model_versions' => \$settingsPayload['models']", $source);
        self::assertStringContainsString("'currency' => \$settingsPayload['budgets']['currency']", $source);
        self::assertStringContainsString("'settings_limits' => \$settingsPayload['limits']", $source);
    }

    #[Test]
    public function snapshot_contract_rejects_cross_tenant_or_inexact_report(): void
    {
        $snapshot = \App\BusinessModules\Addons\EstimateGeneration\Operations\BenchmarkExecutionSnapshot::fromArray($this->executionSnapshot());
        $snapshot->assertDataset(71, 9, 'acceptance', 4, 'sha256:'.str_repeat('a', 64));
        $snapshot->assertReport($this->matchingReport());

        $this->expectException(\DomainException::class);
        $snapshot->assertDataset(72, 9, 'acceptance', 4, 'sha256:'.str_repeat('a', 64));
    }

    #[Test]
    public function snapshot_contract_rejects_wrong_manifest_and_adapter_report(): void
    {
        $snapshot = \App\BusinessModules\Addons\EstimateGeneration\Operations\BenchmarkExecutionSnapshot::fromArray($this->executionSnapshot());
        $report = $this->matchingReport();
        $report['adapter_id'] = 'current-baseline';

        $this->expectException(\DomainException::class);
        $snapshot->assertReport($report);
    }

    /** @return array<string, mixed> */
    private function executionSnapshot(): array
    {
        return [
            'schema_version' => 1, 'organization_id' => 71, 'dataset_id' => 9,
            'dataset_type' => 'acceptance', 'dataset_version' => 4,
            'dataset_content_hash' => 'sha256:'.str_repeat('a', 64),
            'manifest_base_prefix' => 'org-71/estimate-generation/benchmarks/acceptance/',
            'manifest_locator' => 's3://org-71/estimate-generation/benchmarks/acceptance/corpus.json',
            'manifest_sha256' => str_repeat('b', 64), 'adapter_id' => 'production-replay',
            'prompt_version' => 'recorded-ports:v3', 'settings_snapshot_id' => 8,
            'settings_snapshot_version' => 2, 'pipeline_version' => 'pipeline:v4',
            'settings_scope' => 'organization', 'settings_organization_id' => 71,
            'settings_snapshot_hash' => str_repeat('c', 64),
            'settings_limits' => ['max_files' => 20, 'max_pages_per_file' => 500, 'max_total_pages' => 2000],
            'model_versions' => ['vision' => 'openai/gpt-5'], 'normative_version' => 'normative:v7',
            'price_version' => 'price:v5', 'currency' => 'RUB',
        ];
    }

    /** @return array<string, mixed> */
    private function matchingReport(): array
    {
        return [
            'dataset' => 'acceptance', 'manifest_sha256' => str_repeat('b', 64),
            'adapter_id' => 'production-replay', 'prompt_version' => 'recorded-ports:v3',
            'pipeline_version' => 'pipeline:v4', 'model_versions' => ['vision' => 'openai/gpt-5'],
            'normative_version' => 'normative:v7', 'price_version' => 'price:v5', 'currency' => 'RUB',
            'settings_snapshot_id' => 8, 'settings_snapshot_version' => 2,
            'settings_scope' => 'organization', 'settings_organization_id' => 71,
            'settings_snapshot_hash' => str_repeat('c', 64),
            'settings_limits' => ['max_files' => 20, 'max_pages_per_file' => 500, 'max_total_pages' => 2000],
        ];
    }

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
