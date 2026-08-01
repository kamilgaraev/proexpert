<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureDispatcher;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Jobs\MaterializeWorkforceCapacityCaptureJob;
use Illuminate\Database\ConnectionInterface;

final readonly class LaravelWorkforceCapacityDeferredCaptureDispatcher implements WorkforceCapacityDeferredCaptureDispatcher
{
    public function __construct(private ConnectionInterface $connection) {}

    public function dispatchAfterCommit(int $captureRequestId): void
    {
        $this->connection->afterCommit(static function () use ($captureRequestId): void {
            MaterializeWorkforceCapacityCaptureJob::dispatch($captureRequestId)
                ->onConnection('redis_reports')
                ->onQueue('reports')
                ->afterCommit();
        });
    }
}
