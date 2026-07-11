<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final class InProcessBenchmarkCaseExecutor implements BenchmarkCaseExecutor
{
    public function execute(
        BenchmarkCaseExecutionRequest $request,
        BenchmarkCaseData $case,
        BenchmarkPipelineAdapter $adapter,
    ): BenchmarkPipelineResultData {
        return $adapter->run($case, $request->timeoutMs);
    }
}
