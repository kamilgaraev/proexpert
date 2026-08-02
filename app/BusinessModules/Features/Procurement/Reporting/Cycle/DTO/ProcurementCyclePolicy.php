<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProcurementCyclePolicy
{
    public function __construct(
        public DateTimeImmutable $asOf,
        public array $stageSlaSeconds,
        public string $timezone,
        public array $businessWeekdays,
        public string $businessDayStart,
        public string $businessDayEnd,
        public int $cohortMaturitySeconds = 0,
    ) {
        if (! in_array($timezone, timezone_identifiers_list(), true)
            || preg_match('/^\d{2}:\d{2}:\d{2}$/D', $businessDayStart) !== 1
            || preg_match('/^\d{2}:\d{2}:\d{2}$/D', $businessDayEnd) !== 1
            || $businessDayEnd <= $businessDayStart
            || ! array_is_list($businessWeekdays)
            || $businessWeekdays === []
            || $cohortMaturitySeconds < 0) {
            throw new InvalidArgumentException('Procurement cycle business calendar is invalid.');
        }
        $weekdays = [];
        foreach ($businessWeekdays as $weekday) {
            if (! is_int($weekday) || $weekday < 1 || $weekday > 7 || isset($weekdays[$weekday])) {
                throw new InvalidArgumentException('Procurement cycle business weekdays are invalid.');
            }
            $weekdays[$weekday] = true;
        }
        foreach ($stageSlaSeconds as $stage => $seconds) {
            if (! is_string($stage) || trim($stage) === '' || ! is_int($seconds) || $seconds < 0) {
                throw new InvalidArgumentException('Procurement cycle stage SLA is invalid.');
            }
        }
    }
}
