<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemWorkforceCapacityClock implements WorkforceCapacityClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
