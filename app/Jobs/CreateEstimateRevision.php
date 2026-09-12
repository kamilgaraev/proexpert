<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateRevisionQueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class CreateEstimateRevision implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $operationId)
    {
        $this->onConnection('redis_estimate_revisions')->onQueue('estimate-revisions');
    }

    public function handle(EstimateRevisionQueueService $service): void
    {
        $service->process($this->operationId);
    }

    public function failed(?Throwable $exception): void
    {
        app(EstimateRevisionQueueService::class)->fail($this->operationId, $exception);
    }
}
