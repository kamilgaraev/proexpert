<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityLifecycleCaptureDraft;

interface WorkforceCapacityLifecycleCaptureCoordinator
{
    public function beginDismissal(
        int $organizationId,
        int $employeeId,
        string $dismissalDate,
    ): WorkforceCapacityLifecycleCaptureDraft;

    public function finishDismissal(WorkforceCapacityLifecycleCaptureDraft $draft): void;
}
