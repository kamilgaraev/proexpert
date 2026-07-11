<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunOptions;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkRunnerTest extends TestCase
{
    #[Test]
    public function runner_is_deterministic_safe_and_reports_macro_micro_breakdowns_and_unknown_cost(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $manifest = BenchmarkManifest::fromFile($root.'/manifest.json', $root);
        $adapter = new class implements BenchmarkPipelineAdapter
        {
            public function id(): string
            {
                return 'test-adapter';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
            {
                $expected = json_decode((string) file_get_contents($case->expectedPath()), true, 512, JSON_THROW_ON_ERROR);

                return BenchmarkPipelineResultData::success($expected['expected'], ['vision' => 'synthetic:v1'], null, null);
            }
        };
        $runner = new BenchmarkRunner(MetricRegistry::standard(), static fn (): float => 1000.0);
        $options = new BenchmarkRunOptions('pipeline:test:v1', 'prompt:test:v1', 1000, 1);

        $first = $runner->run($manifest, BenchmarkDatasetType::Regression, $adapter, $options);
        $second = $runner->run($manifest, BenchmarkDatasetType::Regression, $adapter, $options);

        self::assertSame($first->deterministicPayload(), $second->deterministicPayload());
        self::assertSame(1, $first->schemaVersion);
        self::assertSame('unknown', $first->costStatus);
        self::assertNull($first->costAmount);
        self::assertSame(count($manifest->casesFor(BenchmarkDatasetType::Regression)), $first->succeededCount);
        self::assertSame(0, $first->failedCount);
        self::assertSame(0, $first->skippedCount);
        self::assertArrayHasKey('per_source', $first->breakdowns);
        self::assertArrayHasKey('per_tag', $first->breakdowns);
        self::assertSame(1.0, $first->metrics['technical_success_rate']['micro']);
        self::assertStringNotContainsString($root, $first->canonicalJson());
        self::assertStringNotContainsString('input_locator', $first->canonicalJson());
    }

    #[Test]
    public function pipeline_exception_is_a_scored_safe_failure_without_message_leak(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $manifest = BenchmarkManifest::fromFile($root.'/manifest.json', $root);
        $adapter = new class implements BenchmarkPipelineAdapter
        {
            public function id(): string
            {
                return 'failing';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
            {
                throw new \RuntimeException('C:\\clients\\secret-house.pdf token=secret');
            }
        };

        $report = (new BenchmarkRunner(MetricRegistry::standard()))->run(
            $manifest,
            BenchmarkDatasetType::Development,
            $adapter,
            new BenchmarkRunOptions('pipeline:test:v1', 'prompt:test:v1', 1000, 0),
        );

        self::assertSame($report->caseCount, $report->failedCount);
        self::assertSame(0.0, $report->metrics['technical_success_rate']['micro']);
        self::assertStringNotContainsString('secret-house', $report->canonicalJson());
        self::assertSame(['pipeline_exception'], array_values(array_unique(array_column($report->caseResults, 'failure_code'))));
    }

    #[Test]
    public function regression_run_never_reads_acceptance_objects(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $manifest = BenchmarkManifest::fromFile($root.'/manifest.json', $root);
        $seen = [];
        $adapter = new class($seen) implements BenchmarkPipelineAdapter
        {
            /** @param array<int, string> $seen */
            public function __construct(private array &$seen) {}

            public function id(): string
            {
                return 'capture';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
            {
                $this->seen[] = $case->dataset->value;

                return BenchmarkPipelineResultData::technicalFailure('not_implemented');
            }
        };

        (new BenchmarkRunner(MetricRegistry::standard()))->run(
            $manifest,
            BenchmarkDatasetType::Regression,
            $adapter,
            new BenchmarkRunOptions('pipeline:test:v1', 'prompt:test:v1', 1000, 1),
        );

        self::assertSame(['regression'], array_values(array_unique($seen)));
    }

    #[Test]
    public function known_costs_are_summed_as_exact_decimals_and_mixed_usage_is_explicit(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $manifest = BenchmarkManifest::fromFile($root.'/manifest.json', $root);
        $attempt = 0;
        $adapter = new class($attempt) implements BenchmarkPipelineAdapter
        {
            public function __construct(private int &$attempt) {}

            public function id(): string
            {
                return 'costed';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
            {
                $this->attempt++;
                $expected = json_decode((string) file_get_contents($case->expectedPath()), true, 64, JSON_THROW_ON_ERROR);

                return BenchmarkPipelineResultData::success(
                    $expected['expected'],
                    ['vision' => 'synthetic:v1'],
                    $this->attempt === 1 ? null : '0.100000001',
                    $this->attempt === 1 ? null : 'RUB',
                );
            }
        };

        $report = (new BenchmarkRunner(MetricRegistry::standard()))->run(
            $manifest,
            BenchmarkDatasetType::Regression,
            $adapter,
            new BenchmarkRunOptions('pipeline:test:v1', 'prompt:test:v1', 1000, 1),
        );

        self::assertSame('partial', $report->costStatus);
        self::assertSame('0.300000003', $report->costAmount);
        self::assertSame('RUB', $report->currency);
        self::assertSame(1, $report->unknownCostAttempts);
    }

    #[Test]
    public function manifest_and_report_pass_recursive_privacy_scan(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $manifest = BenchmarkManifest::fromFile($root.'/manifest.json', $root);
        $report = (new BenchmarkRunner(MetricRegistry::standard()))->run(
            $manifest,
            BenchmarkDatasetType::Regression,
            new class implements BenchmarkPipelineAdapter
            {
                public function id(): string
                {
                    return 'privacy-failure';
                }

                public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
                {
                    return BenchmarkPipelineResultData::technicalFailure('pipeline_not_configured');
                }
            },
            new BenchmarkRunOptions('pipeline:test:v1', 'prompt:test:v1', 1000, 1),
        );
        $content = (string) file_get_contents($root.'/manifest.json').' '.$report->canonicalJson();

        foreach (['C:\\Users\\', '/var/www/', 'presigned', 'X-Amz-', 'client_id', 'access_token', 'secret_key'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $content);
        }
    }

    #[Test]
    public function unsupported_is_skipped_only_with_cli_opt_in_and_manifest_declaration(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $manifest = BenchmarkManifest::fromFile($root.'/manifest.json', $root);
        $adapter = new class implements BenchmarkPipelineAdapter
        {
            public function id(): string
            {
                return 'unsupported';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
            {
                return BenchmarkPipelineResultData::unsupported();
            }
        };
        $runner = new BenchmarkRunner(MetricRegistry::standard());

        $strict = $runner->run($manifest, BenchmarkDatasetType::Regression, $adapter, new BenchmarkRunOptions('pipeline:test:v1', 'prompt:test:v1', 1000, 1));
        $optedIn = $runner->run($manifest, BenchmarkDatasetType::Regression, $adapter, new BenchmarkRunOptions('pipeline:test:v1', 'prompt:test:v1', 1000, 1, 'allow-placeholder:v1', true));

        self::assertSame(4, $strict->failedCount);
        self::assertSame(0, $strict->skippedCount);
        self::assertSame(3, $optedIn->failedCount);
        self::assertSame(1, $optedIn->skippedCount);
        self::assertSame(0.0, $optedIn->metrics['technical_success_rate']['micro']);
    }
}
