<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use DateTimeImmutable;

interface ReportExecutionClock
{
    public function now(): DateTimeImmutable;
}
