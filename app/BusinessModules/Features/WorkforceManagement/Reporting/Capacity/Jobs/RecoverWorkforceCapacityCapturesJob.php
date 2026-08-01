<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Jobs;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureDispatcher;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureStore;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class RecoverWorkforceCapacityCapturesJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct()
    {
        $this->onConnection('redis_reports');
        $this->onQueue('reports');
    }

    public function handle(
        WorkforceCapacityDeferredCaptureStore $store,
        WorkforceCapacityDeferredCaptureDispatcher $dispatcher,
    ): void {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($store->recoverableIds($now, 100, 960) as $requestId) {
            $dispatcher->dispatchAfterCommit((int) $requestId);
        }
    }
}
