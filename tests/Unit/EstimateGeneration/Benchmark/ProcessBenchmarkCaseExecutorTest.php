<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseExecutionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProcessBenchmarkCaseExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\UnixProcessGroupRuntime;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\WindowsProcessTreeRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessBenchmarkCaseExecutorTest extends TestCase
{
    #[Test]
    public function windows_taskkill_uses_direct_argv_and_accepts_exit_zero_after_process_stops(): void
    {
        $commands = [];
        $runtime = new WindowsProcessTreeRuntime(static function (array $command, int $timeoutMicroseconds) use (&$commands): ?int {
            $commands[] = [$command, $timeoutMicroseconds];

            return 0;
        });
        $probes = 0;

        self::assertTrue($runtime->terminatePid(101, static function () use (&$probes): bool {
            return ++$probes < 2;
        }, 50_000));
        self::assertSame(['taskkill', '/PID', '101', '/T', '/F'], $commands[0][0]);
        self::assertGreaterThan(0, $commands[0][1]);
        self::assertLessThanOrEqual(50_000, $commands[0][1]);
    }

    #[Test]
    public function windows_taskkill_rejects_live_process_nonzero_exit_and_timeout(): void
    {
        $live = new WindowsProcessTreeRuntime(static fn (array $command, int $timeoutMicroseconds): ?int => 0);
        $nonzero = new WindowsProcessTreeRuntime(static fn (array $command, int $timeoutMicroseconds): ?int => 5);
        $timeout = new WindowsProcessTreeRuntime(static fn (array $command, int $timeoutMicroseconds): ?int => null);

        self::assertFalse($live->terminatePid(101, static fn (): bool => true, 20_000));
        self::assertFalse($nonzero->terminatePid(101, static fn (): bool => false, 20_000));
        self::assertFalse($timeout->terminatePid(101, static fn (): bool => false, 20_000));

        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Benchmark/ProcessBenchmarkCaseExecutor.php');
        self::assertIsString($source);
        self::assertStringContainsString('$this->windowsRuntime->terminate(', $source);
        self::assertStringContainsString("technicalFailure('worker_process_group_termination_failed')", $source);
    }

    #[Test]
    public function unix_process_group_runtime_discovers_sets_id_without_a_literal_path_and_fails_closed(): void
    {
        $runtime = new UnixProcessGroupRuntime(
            static fn (string $name): ?string => in_array($name, ['setsid', 'kill'], true) ? PHP_BINARY : null,
        );
        $runtime->assertAvailable();
        self::assertSame(PHP_BINARY, $runtime->setsidBinary());
        self::assertSame(PHP_BINARY, $runtime->wrap(['php', 'worker.php'])[0]);
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Benchmark/ProcessBenchmarkCaseExecutor.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('/usr/bin/setsid', $source);

        $this->expectException(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException::class);
        (new UnixProcessGroupRuntime(static fn (): ?string => null))->setsidBinary();
    }

    #[Test]
    public function hanging_worker_is_killed_with_bounded_wall_time_and_safe_timeout_code(): void
    {
        $treeMarker = sys_get_temp_dir().'/most-benchmark-tree-'.bin2hex(random_bytes(8)).'.txt';
        putenv('MOST_BENCHMARK_TREE_MARKER='.$treeMarker);
        $executor = new ProcessBenchmarkCaseExecutor(
            phpBinary: PHP_BINARY,
            artisanPath: dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks/hanging-worker.php',
            maxOutputBytes: 4096,
            memoryLimit: '64M',
        );
        $started = microtime(true);

        $case = BenchmarkManifest::fromFile(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks/manifest.json',
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks',
        )->case('reg-dxf-001');
        $adapter = new class implements \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineAdapter
        {
            public function id(): string
            {
                return 'current-baseline';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData $case, int $timeoutMs): \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData
            {
                return \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData::technicalFailure('not_called');
            }
        };
        try {
            $result = $executor->execute(new BenchmarkCaseExecutionRequest(
                'repository:v1', 'reg-dxf-001', 'current-baseline', 200,
            ), $case, $adapter);
        } finally {
            putenv('MOST_BENCHMARK_TREE_MARKER');
        }

        $elapsed = microtime(true) - $started;
        self::assertGreaterThanOrEqual(0.15, $elapsed);
        self::assertLessThan(1.5, $elapsed);
        self::assertSame('technical_failure', $result->status);
        self::assertSame(
            PHP_OS_FAMILY === 'Windows' ? 'worker_process_group_termination_failed' : 'case_timeout',
            $result->failureCode,
        );
        usleep(6_000_000);
        self::assertFileDoesNotExist($treeMarker);
    }
}
