<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class FakeReportExecutionClock implements ReportExecutionClock
{
    private DateTimeImmutable $instant;

    public function __construct(DateTimeImmutable $instant)
    {
        $this->instant = $instant->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function advance(DateInterval $interval): void
    {
        $this->instant = $this->instant->add($interval);
    }
}
