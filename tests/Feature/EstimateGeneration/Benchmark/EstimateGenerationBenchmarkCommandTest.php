<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkGate;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkAdapterRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkReportOutputStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\InProcessBenchmarkCaseExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProductionImmutableBenchmarkReportOutputStore;
use App\BusinessModules\Addons\EstimateGeneration\Console\Commands\RunEstimateGenerationBenchmarkCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class EstimateGenerationBenchmarkCommandTest extends TestCase
{
    private string $fixtureRoot;

    private string $outputRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $this->outputRoot = sys_get_temp_dir().'/most-benchmark-command-'.bin2hex(random_bytes(4));
        mkdir($this->outputRoot, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputRoot.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->outputRoot)) {
            rmdir($this->outputRoot);
        }
        parent::tearDown();
    }

    #[Test]
    public function command_runs_without_laravel_bootstrap_or_database_and_emits_canonical_json(): void
    {
        $tester = $this->tester([$this->passingAdapter()]);
        $exit = $tester->execute([
            '--dataset' => 'regression',
            '--format' => 'json',
            '--adapter' => 'fixture-pipeline',
            '--pipeline-version' => 'fixture-pipeline:v1',
        ]);

        self::assertSame(0, $exit);
        $payload = json_decode(trim($tester->getDisplay()), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('regression', $payload['dataset']);
        self::assertSame('fixture-pipeline', $payload['adapter_id']);
    }

    #[Test]
    public function production_rejects_local_output_before_loading_acceptance(): void
    {
        $tester = $this->tester([$this->passingAdapter()], 'production', '1', 's3://org-1/estimate-generation/benchmarks/acceptance/manifest.json');
        self::assertSame(1, $tester->execute([
            '--dataset' => 'acceptance',
            '--adapter' => 'fixture-pipeline',
            '--pipeline-version' => 'fixture-pipeline:v1',
        ]));
        self::assertStringContainsString('production_output_store_invalid', $tester->getDisplay());
    }

    #[Test]
    public function production_rejects_repository_dataset_before_adapter_or_fixture_access(): void
    {
        $adapter = new class implements BenchmarkPipelineAdapter
        {
            public int $calls = 0;

            public function id(): string
            {
                return 'production-spy';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
            {
                $this->calls++;

                return BenchmarkPipelineResultData::technicalFailure('unexpected');
            }
        };
        $tester = $this->tester([$adapter], 'production');
        self::assertSame(1, $tester->execute([
            '--dataset' => 'regression', '--adapter' => 'production-spy', '--pipeline-version' => 'production-spy:v1',
        ]));
        self::assertSame(0, $adapter->calls);
        self::assertStringContainsString('repository_benchmark_forbidden_in_production', $tester->getDisplay());
    }

    #[Test]
    public function production_writes_report_through_injected_immutable_store(): void
    {
        [$loader, $manifest] = $this->acceptanceLoader();
        $store = new class implements ProductionImmutableBenchmarkReportOutputStore
        {
            public ?string $locator = null;

            public function write(string $locator, string $contents): string
            {
                $this->locator = $locator;
                \PHPUnit\Framework\Assert::assertStringContainsString('"dataset":"acceptance"', $contents);

                return $locator.'?versionId=v1';
            }
        };
        $output = 's3://org-42/estimate-generation/benchmarks/123e4567-e89b-12d3-a456-426614174000/'.str_repeat('a', 64).'.json';
        $tester = $this->tester([$this->passingAdapter()], 'production', null, $manifest, 42, $loader, $store);
        self::assertSame(0, $tester->execute([
            '--dataset' => 'acceptance', '--adapter' => 'fixture-pipeline', '--pipeline-version' => 'fixture-pipeline:v1', '--output' => $output,
        ]));
        self::assertSame($output, $store->locator);
    }

    #[Test]
    public function acceptance_rejects_missing_flag_or_org_configuration_and_runs_with_all_gates(): void
    {
        [$loader, $locator] = $this->acceptanceLoader();
        $arguments = ['--dataset' => 'acceptance', '--adapter' => 'fixture-pipeline', '--pipeline-version' => 'fixture-pipeline:v1'];

        self::assertSame(1, $this->tester([$this->passingAdapter()], 'testing', null, $locator, 42, $loader)->execute($arguments));
        self::assertSame(1, $this->tester([$this->passingAdapter()], 'testing', '1', $locator, null, $loader)->execute($arguments));
        $tester = $this->tester([$this->passingAdapter()], 'testing', '1', $locator, 42, $loader);
        self::assertSame(0, $tester->execute($arguments));
        self::assertStringContainsString('"dataset":"acceptance"', $tester->getDisplay());
    }

    #[Test]
    public function acceptance_preflight_failure_never_calls_adapter_or_writes_report(): void
    {
        [$loader, $locator] = $this->acceptanceLoader(true);
        $adapter = new class implements BenchmarkPipelineAdapter
        {
            public int $calls = 0;

            public function id(): string
            {
                return 'preflight-spy';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
            {
                $this->calls++;

                return BenchmarkPipelineResultData::technicalFailure('must_not_run');
            }
        };
        $tester = $this->tester([$adapter], 'testing', '1', $locator, 42, $loader);

        self::assertSame(1, $tester->execute([
            '--dataset' => 'acceptance',
            '--adapter' => 'preflight-spy',
            '--pipeline-version' => 'preflight-spy:v1',
            '--output' => 'preflight.json',
        ]));
        self::assertSame(0, $adapter->calls);
        self::assertFileDoesNotExist($this->outputRoot.'/preflight.json');
        self::assertStringContainsString('expected_contract_invalid', $tester->getDisplay());
    }

    #[Test]
    public function output_is_restricted_to_benchmark_root_and_never_overwrites(): void
    {
        $tester = $this->tester([$this->passingAdapter()]);
        self::assertSame(1, $tester->execute([
            '--adapter' => 'fixture-pipeline',
            '--pipeline-version' => 'fixture-pipeline:v1',
            '--output' => '../outside.json',
        ]));

        file_put_contents($this->outputRoot.'/existing.json', '{}');
        $tester = $this->tester([$this->passingAdapter()]);
        self::assertSame(1, $tester->execute([
            '--adapter' => 'fixture-pipeline',
            '--pipeline-version' => 'fixture-pipeline:v1',
            '--output' => 'existing.json',
        ]));
    }

    #[Test]
    public function unknown_adapter_format_dataset_and_invalid_report_exit_nonzero(): void
    {
        foreach ([
            ['--dataset' => 'training'],
            ['--format' => 'xml'],
            ['--adapter' => 'production-hidden'],
        ] as $options) {
            $arguments = $options + [
                '--adapter' => 'fixture-pipeline',
                '--pipeline-version' => 'fixture-pipeline:v1',
            ];
            self::assertSame(1, $this->tester([$this->passingAdapter()])->execute($arguments));
        }

        self::assertSame(1, $this->tester([$this->passingAdapter()])->execute([]));
        self::assertSame(1, $this->tester([$this->passingAdapter()])->execute([
            '--adapter' => 'fixture-pipeline',
            '--pipeline-version' => 'fixture-pipeline:v1',
            '--max-failure-rate' => '0.1',
        ]));
    }

    #[Test]
    public function command_returns_nonzero_when_failure_threshold_is_exceeded(): void
    {
        $adapter = new class implements BenchmarkPipelineAdapter
        {
            public function id(): string
            {
                return 'always-fails';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
            {
                return BenchmarkPipelineResultData::technicalFailure('provider_unavailable');
            }
        };

        self::assertSame(1, $this->tester([$adapter])->execute([
            '--adapter' => 'always-fails',
            '--pipeline-version' => 'always-fails:v1',
            '--max-failure-rate' => '0',
        ]));
    }

    /** @param list<BenchmarkPipelineAdapter> $adapters */
    private function tester(
        array $adapters,
        string $environment = 'testing',
        ?string $gate = null,
        ?string $acceptanceLocator = null,
        ?int $acceptanceOrganizationId = null,
        ?AcceptanceBenchmarkCorpusLoader $acceptanceLoader = null,
        ?BenchmarkReportOutputStore $reportOutput = null,
    ): CommandTester {
        $command = new RunEstimateGenerationBenchmarkCommand(
            new BenchmarkRunner(MetricRegistry::standard(), new InProcessBenchmarkCaseExecutor, static fn (): float => 1000.0),
            new BenchmarkAdapterRegistry($adapters),
            $this->fixtureRoot.'/manifest.json',
            $this->fixtureRoot,
            $this->outputRoot,
            static fn (): string => $environment,
            static fn (string $key): ?string => $key === 'RUN_ESTIMATE_GENERATION_ACCEPTANCE_BENCHMARK' ? $gate : null,
            $acceptanceLocator,
            $acceptanceOrganizationId,
            $acceptanceLoader,
            null,
            $reportOutput,
            new AcceptanceBenchmarkGate,
        );
        $container = new class extends Container
        {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $container->bind(OutputStyle::class, static fn (Container $app, array $parameters): OutputStyle => new OutputStyle(
            $parameters['input'],
            $parameters['output'],
        ));
        $container->bind(Factory::class, static fn (Container $app, array $parameters): Factory => new Factory(
            $parameters['output'],
        ));
        $command->setLaravel($container);

        return new CommandTester($command);
    }

    private function passingAdapter(): BenchmarkPipelineAdapter
    {
        return new class implements BenchmarkPipelineAdapter
        {
            public function id(): string
            {
                return 'fixture-pipeline';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
            {
                return BenchmarkPipelineResultData::success(
                    [
                        'sheet_type' => 'floor_plan', 'room_cells' => [], 'wall_cells' => [], 'opening_ids' => [],
                        'areas' => [], 'quantities' => [], 'work_ids' => [], 'normative_rankings' => [], 'costs' => [],
                        'applicable_item_ids' => [], 'evidence_ids_by_item' => [],
                        'model_schema_version' => 'benchmark-prediction:v1',
                    ],
                    ['fixture' => 'fixture:v1'],
                    '0',
                    'RUB',
                );
            }
        };
    }

    /** @return array{AcceptanceBenchmarkCorpusLoader, string} */
    private function acceptanceLoader(bool $withInvalidUnsupportedCase = false): array
    {
        $expected = json_encode([
            'schema_version' => 1, 'expected_model_schema_version' => 'benchmark-expected:v1',
            'expected' => [
                'sheet_type' => 'floor_plan', 'room_cells' => [], 'wall_cells' => [], 'opening_ids' => [],
                'areas' => [], 'quantities' => [], 'work_ids' => [], 'normative_rankings' => [], 'costs' => [],
                'applicable_item_ids' => [], 'evidence_ids_by_item' => [],
            ],
        ], JSON_THROW_ON_ERROR);
        $cases = [];
        $objects = [];
        for ($index = 1; $index <= 6; $index++) {
            $input = "P3\n1 1\n255\n{$index} {$index} {$index}\n";
            $caseExpected = str_replace('"floor_plan"', '"floor_plan"', $expected).str_repeat(' ', $index);
            $cases[] = [
                'id' => 'acceptance-command-00'.$index, 'dataset' => 'acceptance', 'source_type' => 'photo_plan',
                'input_locator' => "s3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case-{$index}/input.ppm",
                'expected_locator' => "s3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case-{$index}/expected.json",
                'input_sha256' => hash('sha256', $input), 'expected_sha256' => hash('sha256', $caseExpected),
                'license' => 'private-approved', 'provenance' => 'private:approved', 'tags' => ['private'],
                'schema_version' => 1, 'expected_model_schema_version' => 'benchmark-expected:v1',
                'allowed_capabilities' => ['document_understanding'],
            ];
            $objects["org-42/estimate-generation/benchmarks/acceptance/case-{$index}/input.ppm"] = $input;
            $objects["org-42/estimate-generation/benchmarks/acceptance/case-{$index}/expected.json"] = $caseExpected;
        }
        if ($withInvalidUnsupportedCase) {
            $invalidExpected = '{"schema_version":1}';
            $cases[] = array_merge($cases[0], [
                'id' => 'acceptance-command-dwg-002', 'source_type' => 'dwg',
                'input_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/unsupported.dwg',
                'expected_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/unsupported.json',
                'input_sha256' => hash('sha256', "AC1032 SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1\n"),
                'expected_sha256' => hash('sha256', $invalidExpected),
                'tags' => ['private', 'unsupported_conversion'],
                'allowed_capabilities' => ['descriptor_validation', 'unsupported_conversion'],
            ]);
            $objects['org-42/estimate-generation/benchmarks/acceptance/case/unsupported.dwg'] = "AC1032 SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1\n";
            $objects['org-42/estimate-generation/benchmarks/acceptance/case/unsupported.json'] = $invalidExpected;
        }
        $manifest = json_encode([
            'schema_version' => 1, 'manifest_version' => 'acceptance-command:v1',
            'cases' => $cases,
        ], JSON_THROW_ON_ERROR);
        $objects['org-42/estimate-generation/benchmarks/acceptance/manifest.json'] = $manifest;
        $store = new class($objects) implements BenchmarkPrivateObjectStore
        {
            /** @param array<string, string> $objects */
            public function __construct(private array $objects) {}

            public function read(string $path, int $maxBytes): string
            {
                return $this->objects[$path] ?? throw new BenchmarkContractException('private_object_unavailable');
            }
        };

        return [new AcceptanceBenchmarkCorpusLoader($store), 's3://org-42/estimate-generation/benchmarks/acceptance/manifest.json'];
    }
}
