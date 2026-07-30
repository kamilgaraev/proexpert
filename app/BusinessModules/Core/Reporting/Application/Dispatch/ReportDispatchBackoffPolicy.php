<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ReportDispatchBackoffPolicy
{
    public function __construct(
        private readonly ReportExecutionRuntimeConfiguration $runtime,
    ) {}

    public function nextAttemptAt(int $attempt, DateTimeImmutable $occurredAt): DateTimeImmutable
    {
        if ($attempt < 1 || $attempt > $this->runtime->dispatchMaxAttempts) {
            throw new InvalidArgumentException('report_dispatch_attempt_invalid');
        }

        $delay = min(3600, 15 * (2 ** ($attempt - 1)));
        $base = $occurredAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->setTime(
                (int) $occurredAt->setTimezone(new DateTimeZone('UTC'))->format('H'),
                (int) $occurredAt->setTimezone(new DateTimeZone('UTC'))->format('i'),
                (int) $occurredAt->setTimezone(new DateTimeZone('UTC'))->format('s'),
                0,
            );

        return $base->modify("+{$delay} seconds");
    }
}
