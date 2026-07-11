<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseExecutionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProcessBenchmarkCaseExecutor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessBenchmarkCaseExecutorTest extends TestCase
{
    #[Test]
    public function hanging_worker_is_killed_with_bounded_wall_time_and_safe_timeout_code(): void
    {
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

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData $case, int $timeoutMs): \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData
            {
                return \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData::technicalFailure('not_called');
            }
        };
        $result = $executor->execute(new BenchmarkCaseExecutionRequest(
            'repository:v1', 'reg-dxf-001', 'current-baseline', 200,
        ), $case, $adapter);

        self::assertLessThan(2.0, microtime(true) - $started);
        self::assertSame('technical_failure', $result->status);
        self::assertSame('case_timeout', $result->failureCode);
    }
}
