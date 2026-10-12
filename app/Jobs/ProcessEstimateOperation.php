<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\BudgetEstimates\Services\Operations\EstimateOperationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessEstimateOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 600;
    public bool $failOnTimeout = true;

    public function __construct(public readonly string $operationId, public readonly int $generation, public readonly bool $apply = false)
    {
        // Reuse the existing long-running estimate worker (660s), not the 60s default worker.
        $this->onConnection('redis_estimate_revisions')->onQueue('estimate-revisions');
    }

    public function handle(EstimateOperationService $service): void
    {
        $service->process($this->operationId, $this->generation, $this->apply);
    }

    public function failed(?Throwable $exception): void
    {
        app(EstimateOperationService::class)->fail($this->operationId, $this->generation, $exception);
    }
}
