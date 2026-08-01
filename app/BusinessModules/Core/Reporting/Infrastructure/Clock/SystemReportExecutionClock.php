<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Clock;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use DateTimeImmutable;
use DateTimeZone;

final class SystemReportExecutionClock implements ReportExecutionClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
