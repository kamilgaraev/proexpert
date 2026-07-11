<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkAdapterRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\InProcessBenchmarkCaseExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricRegistry;
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
    public function acceptance_requires_all_gates_and_is_refused_in_production(): void
    {
        $tester = $this->tester([$this->passingAdapter()], 'production', '1', 's3://org-1/estimate-generation/benchmarks/acceptance/manifest.json');
        self::assertSame(1, $tester->execute([
            '--dataset' => 'acceptance',
            '--adapter' => 'fixture-pipeline',
            '--pipeline-version' => 'fixture-pipeline:v1',
        ]));
        self::assertStringContainsString('acceptance_forbidden_in_production', $tester->getDisplay());
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

            public function run(BenchmarkCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
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

            public function run(BenchmarkCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
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
    private function acceptanceLoader(): array
    {
        $expected = json_encode([
            'schema_version' => 1, 'expected_model_schema_version' => 'benchmark-expected:v1',
            'expected' => [
                'sheet_type' => 'floor_plan', 'room_cells' => [], 'wall_cells' => [], 'opening_ids' => [],
                'areas' => [], 'quantities' => [], 'work_ids' => [], 'normative_rankings' => [], 'costs' => [],
                'applicable_item_ids' => [], 'evidence_ids_by_item' => [],
            ],
        ], JSON_THROW_ON_ERROR);
        $input = 'private-input';
        $manifest = json_encode([
            'schema_version' => 1, 'manifest_version' => 'acceptance-command:v1',
            'cases' => [[
                'id' => 'acceptance-command-001', 'dataset' => 'acceptance', 'source_type' => 'vector_pdf',
                'input_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/input.pdf',
                'expected_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/expected.json',
                'input_sha256' => hash('sha256', $input), 'expected_sha256' => hash('sha256', $expected),
                'license' => 'private-approved', 'provenance' => 'private:approved', 'tags' => ['private'],
                'schema_version' => 1, 'expected_model_schema_version' => 'benchmark-expected:v1',
                'allowed_capabilities' => ['document_understanding'],
            ]],
        ], JSON_THROW_ON_ERROR);
        $store = new class(['org-42/estimate-generation/benchmarks/acceptance/manifest.json' => $manifest, 'org-42/estimate-generation/benchmarks/acceptance/case/input.pdf' => $input, 'org-42/estimate-generation/benchmarks/acceptance/case/expected.json' => $expected]) implements BenchmarkPrivateObjectStore
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
