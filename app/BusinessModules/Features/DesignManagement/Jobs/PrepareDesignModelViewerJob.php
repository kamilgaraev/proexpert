<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Jobs;

use App\BusinessModules\Features\DesignManagement\Services\DesignModelViewerPreparationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class PrepareDesignModelViewerJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'ifc-processing';

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $derivativeId,
    ) {
        $this->timeout = (int) config('design_management.viewer_job_timeout', 6900);
        $this->onConnection('redis_ifc');
        $this->onQueue(self::QUEUE);
    }

    public function handle(DesignModelViewerPreparationService $service): void
    {
        $service->processQueuedDerivative($this->derivativeId);
    }

    public function failed(Throwable $exception): void
    {
        app(DesignModelViewerPreparationService::class)->markJobFailed($this->derivativeId, $exception);
    }
}
