<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Operations\StoredBenchmarkRunExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RunEstimateGenerationBenchmarkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $runId,
        public readonly string $idempotencyKey,
    ) {
        $this->onQueue('estimate-generation-benchmarks');
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('estimate-generation-benchmark:'.$this->runId))->expireAfter(3700)];
    }

    public function handle(StoredBenchmarkRunExecutor $executor): void
    {
        $executor->execute($this->runId, $this->idempotencyKey);
    }

    public function failed(Throwable $exception): void
    {
        $run = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun::query()->find($this->runId);
        if ($run instanceof \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun
            && $run->status === \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun::STATUS_RUNNING) {
            app(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunRepository::class)->fail(
                (int) $run->organization_id,
                (string) $run->uuid,
                'benchmark_job_exhausted',
                'benchmark_execution_failed',
            );
        }
    }
}
