<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;

interface WorkforceCapacityCurrentSource
{
    public function affectedCohorts(WorkforceCapacityCaptureCommand $command, string $asOfDate): iterable;

    public function readBatch(WorkforceCapacityCaptureCommand $command, array $keys): array;
}
