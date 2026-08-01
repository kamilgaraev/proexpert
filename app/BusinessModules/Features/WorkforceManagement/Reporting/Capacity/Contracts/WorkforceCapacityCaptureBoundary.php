<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureResult;

interface WorkforceCapacityCaptureBoundary
{
    public function capture(WorkforceCapacityCaptureCommand $command): WorkforceCapacityCaptureResult;
}
