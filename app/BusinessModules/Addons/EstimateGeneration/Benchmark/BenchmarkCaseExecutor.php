<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

interface BenchmarkCaseExecutor
{
    public function execute(
        BenchmarkCaseExecutionRequest $request,
        BenchmarkCaseData $case,
        BenchmarkPipelineAdapter $adapter,
    ): BenchmarkPipelineResultData;
}
