<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicyDefinition;
use DateTimeImmutable;

final class ProcurementBusinessCalendar
{
    public function businessSeconds(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ProcurementCyclePolicyDefinition $policy,
    ): int {
        if ($end <= $start) {
            return 0;
        }

        $timezone = $policy->timezoneObject;
        $localDay = $start->setTimezone($timezone)->setTime(0, 0);
        $lastDay = $end->setTimezone($timezone)->setTime(0, 0);
        $seconds = 0;

        while ($localDay <= $lastDay) {
            $date = $localDay->format('Y-m-d');
            $weekday = (int) $localDay->format('N');
            $windows = array_key_exists($date, $policy->exceptions)
                ? $policy->exceptions[$date]
                : ($policy->weeklyWindows[$weekday] ?? []);

            foreach ($windows as [$from, $to]) {
                $windowStart = new DateTimeImmutable("{$date} {$from}:00", $timezone);
                $windowEnd = new DateTimeImmutable("{$date} {$to}:00", $timezone);
                $intersectionStart = max($start->getTimestamp(), $windowStart->getTimestamp());
                $intersectionEnd = min($end->getTimestamp(), $windowEnd->getTimestamp());
                if ($intersectionEnd > $intersectionStart) {
                    $seconds += $intersectionEnd - $intersectionStart;
                }
            }

            $localDay = $localDay->modify('+1 day');
        }

        return $seconds;
    }
}
