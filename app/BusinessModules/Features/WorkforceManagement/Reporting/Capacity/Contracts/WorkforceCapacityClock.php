<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

use DateTimeImmutable;

interface WorkforceCapacityClock
{
    public function now(): DateTimeImmutable;
}
