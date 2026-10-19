<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunOptions;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\InProcessBenchmarkCaseExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Planning\EvaluationSectionMap;
use Brick\Math\BigDecimal;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\EstimateGeneration\NativeHouseBenchmarkAdapter;

final class NativeHouseBenchmarkTest extends TestCase
{
    private string $root;

    private object $database;

    protected function setUp(): void
    {
        parent::setUp();
        $repositoryRoot = dirname(__DIR__, 4);
        $this->root = $repositoryRoot.'/tests/Fixtures/EstimateGeneration/native-house-v1';
        $container = new Container;
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('translator', new Translator(new FileLoader(new Filesystem, $repositoryRoot.'/lang'), 'ru'));
        $this->database = new class
        {
            public int $calls = 0;

            public function __call(string $name, array $arguments): never
            {
                $this->calls++;
                throw new RuntimeException('native_house_database_forbidden');
            }
        };
        $container->instance('db', $this->database);
        $container->instance(Factory::class, new Factory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Container::setInstance($container);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_native_control_replay_is_deterministic_without_database_or_http(): void
    {
        $manifest = BenchmarkManifest::fromFile($this->root.'/manifest.json', $this->root, requireAllSourceTypes: false);
        $adapter = new NativeHouseBenchmarkAdapter(new LocalBenchmarkObjectReader($this->root));
        foreach ($manifest->casesFor(BenchmarkDatasetType::Regression) as $case) {
            $predictionCase = BenchmarkPredictionCaseData::fromCase($case);
            $first = $adapter->run($predictionCase, 20000);
            $second = $adapter->run($predictionCase, 20000);
            self::assertSame('success', $first->status);
            self::assertSame($first->protocolJson(), $second->protocolJson());
            self::assertSame([], $first->prediction['costs']);
            self::assertSame([], $first->prediction['applicable_item_ids']);
        }
        self::assertSame(0, $this->database->calls);
        Http::assertNothingSent();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_pinned_native_controls_use_real_quantities_and_candidates_before_reading_independent_oracles(): void
    {
        $objects = new class(new LocalBenchmarkObjectReader($this->root)) implements BenchmarkObjectReader
        {
            public array $reads = [];

            public function __construct(private readonly BenchmarkObjectReader $delegate) {}

            public function read(BenchmarkCaseData|BenchmarkPredictionCaseData $case, string $role, int $maxBytes): string
            {
                $this->reads[] = [$case->id, $role, $case instanceof BenchmarkPredictionCaseData];

                return $this->delegate->read($case, $role, $maxBytes);
            }
        };
        $manifest = BenchmarkManifest::fromFile($this->root.'/manifest.json', $this->root, requireAllSourceTypes: false);
        $adapter = new NativeHouseBenchmarkAdapter($objects);
        $report = (new BenchmarkRunner(MetricRegistry::standard(), new InProcessBenchmarkCaseExecutor))->run($manifest,
            BenchmarkDatasetType::Regression, $adapter, new BenchmarkRunOptions('native-control:v1', 'no-model:v1', 20000, 0), $objects);
        self::assertSame(5, $report->succeededCount, json_encode($report->caseResults, JSON_THROW_ON_ERROR));
        self::assertSame(0, $report->failedCount);
        self::assertSame('known', $report->costStatus);
        self::assertSame(0, $report->unknownCostAttempts);
        self::assertTrue(BigDecimal::of($report->costAmount)->isZero());
        $byId = array_column($report->caseResults, null, 'case_id');
        $units = ['foundation_beam_volume' => 'm3', 'foundation_slab_volume' => 'm3', 'earthwork_volume' => 'm3',
            'water_pipe_length' => 'm', 'sewer_pipe_length' => 'm', 'cable_length' => 'm', 'duct_length' => 'm',
            'radiator_count' => 'count', 'equipment_count' => 'count'];
        foreach (['known-inputs', 'missing-height', 'source-conflict', 'many-entities'] as $id) {
            self::assertSame(1.0, $byId[$id]['metrics']['work_recall'], $id);
            self::assertSame(1.0, $byId[$id]['metrics']['quantity_mape'], $id);
            $diagnostic = $adapter->diagnostics[$id];
            self::assertCount(10, $diagnostic['selected_sections']);
            self::assertSame(['equipment'], $diagnostic['uncovered_sections']);
            self::assertFalse($diagnostic['accuracy_calibrated']);
            self::assertFalse($diagnostic['estimate_complete']);
            self::assertFalse($diagnostic['paid_actions_enabled']);
            self::assertSame([], $diagnostic['persisted_quantities']);
            self::assertSame([], $diagnostic['current_quantities']);
            foreach ($diagnostic['compiled']['local_estimates'] as $estimate) {
                self::assertContains(EvaluationSectionMap::forPackage($estimate['key']), $diagnostic['selected_sections']);
            }
            $expected = json_decode(file_get_contents($this->root.'/'.$id.'.expected.json'), true, 32, JSON_THROW_ON_ERROR)['expected']['quantities'];
            $actual = [];
            foreach ($diagnostic['native']['quantities'] as $quantity) {
                $key = $quantity->formulaInputs['entity_id'].':'.$quantity->formulaKey;
                self::assertArrayNotHasKey($key, $actual);
                $actual[$key] = $quantity->amount;
                self::assertNotEmpty($quantity->evidenceIds);
                self::assertSame('1', $quantity->formulaVersion);
                self::assertSame($units[$quantity->formulaKey], $quantity->unit);
                self::assertTrue(BigDecimal::of($quantity->amount)->isGreaterThan('0'));
            }
            ksort($actual, SORT_STRING);
            ksort($expected, SORT_STRING);
            self::assertSame(array_keys($expected), array_keys($actual));
            foreach ($expected as $key => $amount) {
                self::assertTrue(BigDecimal::of($actual[$key])->isEqualTo($amount), $id.' '.$key);
            }
            self::assertNotEmpty($diagnostic['items']);
            foreach ($diagnostic['items'] as $item) {
                self::assertSame('not_calculated', $item['pricing_status']);
                self::assertNull($item['price_source']);
                foreach (['materials', 'labor', 'machinery', 'other_resources'] as $resourceKey) {
                    self::assertSame([], $item[$resourceKey]);
                }
            }
        }
        self::assertSame(0.0, $byId['oracle-canary']['metrics']['work_recall']);
        self::assertSame($adapter->diagnostics['known-inputs']['compiled'], $adapter->diagnostics['oracle-canary']['compiled']);
        self::assertSame(10, count($objects->reads));
        foreach (array_chunk($objects->reads, 2) as [$input, $oracle]) {
            self::assertSame([$input[0], 'input', true], $input);
            self::assertSame([$input[0], 'expected', false], $oracle);
        }
        $missing = $adapter->diagnostics['missing-height'];
        self::assertArrayNotHasKey('foundation_volume', $missing['native']['quantities']);
        $warnings = array_values(array_filter($missing['native']['warnings'], static fn (array $row): bool => ($row['entity_id'] ?? null) === 'beam'));
        self::assertSame('operand_missing', $warnings[0]['inputs'][0]['code']);
        self::assertSame('height', $warnings[0]['inputs'][0]['operand']);
        $conflicting = $adapter->diagnostics['source-conflict']['snapshot'];
        self::assertCount(1, $conflicting->conflicts);
        self::assertCount(2, $conflicting->conflicts[0]->facts);
        self::assertCount(2, array_unique(array_column($conflicting->conflicts[0]->facts, 'sourceVersion')));
        self::assertSame(['400', '600'], array_values(array_map(static fn ($fact): string => $fact->value, $conflicting->conflicts[0]->facts)));
        self::assertCount(72, $adapter->diagnostics['many-entities']['native']['quantities']);
        self::assertSame(0, $this->database->calls);
        Http::assertNothingSent();
        self::assertLessThan(512 * 1024 * 1024, memory_get_peak_usage(true));
    }
}
