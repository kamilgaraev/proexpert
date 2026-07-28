<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ReportDispatchBackoffPolicy
{
    public function nextAttemptAt(int $attempt, DateTimeImmutable $occurredAt): DateTimeImmutable
    {
        if ($attempt < 1 || $attempt > 12) {
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
