<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Jobs;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacityDeferredCaptureProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class MaterializeWorkforceCapacityCaptureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly int $captureRequestId)
    {
        $this->onConnection('redis_reports');
        $this->onQueue('reports');
    }

    public function handle(WorkforceCapacityDeferredCaptureProcessor $processor): void
    {
        $processor->process($this->captureRequestId);
    }
}
