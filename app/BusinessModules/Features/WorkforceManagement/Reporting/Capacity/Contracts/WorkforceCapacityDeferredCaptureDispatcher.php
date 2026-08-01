<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

interface WorkforceCapacityDeferredCaptureDispatcher
{
    public function dispatchAfterCommit(int $captureRequestId): void;
}
