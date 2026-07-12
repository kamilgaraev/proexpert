<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseExecutionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProcessBenchmarkCaseExecutor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkWorkerCommandTest extends TestCase
{
    #[Test]
    public function production_worker_command_resolves_real_adapter_and_returns_closed_protocol(): void
    {
        $root = dirname(__DIR__, 4);
        $fixtureRoot = $root.'/tests/Fixtures/EstimateGeneration/benchmarks';
        $case = BenchmarkManifest::fromFile($fixtureRoot.'/manifest.json', $fixtureRoot)->case('dev-vector-pdf-001');
        $executor = new ProcessBenchmarkCaseExecutor(PHP_BINARY, $root.'/artisan');

        $result = $executor->execute(new BenchmarkCaseExecutionRequest(
            'repository:v1', $case->id, 'current-baseline', 15_000,
        ), $case, new class implements \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineAdapter
        {
            public function id(): string
            {
                return 'must-not-run-parent';
            }

            public function run(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData $case, int $timeoutMs): \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData
            {
                throw new \LogicException('Parent adapter executed.');
            }
        });

        self::assertSame('technical_failure', $result->status);
        self::assertSame('normalized_building_model_required', $result->failureCode);
        self::assertSame([], $result->prediction);
    }
}
