<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;

interface WorkforceCapacityCohortLock
{
    public function acquire(WorkforceCapacityCohortKey $key): void;
}
