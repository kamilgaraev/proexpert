<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCaptureReceipt;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use DateTimeImmutable;

interface WorkforceCapacityFrozenCaptureWriter
{
    public function freezeAndEnqueue(
        WorkforceCapacityCaptureCommand $command,
        WorkforceCapacityPolicyDefinition $policy,
        DateTimeImmutable $capturedAt,
        string $businessDate,
    ): WorkforceCapacityFrozenCaptureReceipt;
}
