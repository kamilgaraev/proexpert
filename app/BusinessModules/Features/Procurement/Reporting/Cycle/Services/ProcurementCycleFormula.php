<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleMetric;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicy;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTimeline;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Exceptions\NonMonotonicProcurementTimeline;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final readonly class ProcurementCycleFormula
{
    public function calculate(
        ProcurementProcessTimeline $timeline,
        ProcurementCyclePolicy $policy,
    ): ProcurementCycleMetric {
        $durations = [];
        $events = $timeline->events;
        $last = null;
        $seen = [];

        foreach ($events as $index => $event) {
            if ($last !== null && $event->occurredAt < $last->occurredAt) {
                throw new NonMonotonicProcurementTimeline('Procurement process timestamps must be monotonic.');
            }
            if (isset($seen[$event->code])) {
                throw new DomainException('Procurement process event code must be unique within a timeline.');
            }
            $seen[$event->code] = true;

            $next = $events[$index + 1] ?? null;
            $terminal = in_array($event->code, ['fully_received', 'cancelled'], true);
            $end = $next?->occurredAt ?? ($terminal ? $event->occurredAt : $policy->asOf);
            if ($end < $event->occurredAt) {
                throw new NonMonotonicProcurementTimeline('Procurement process timestamps must be monotonic.');
            }

            $durations[$event->code] = $this->businessSeconds($event->occurredAt, $end, $policy);
            $last = $event;
        }

        $closed = in_array($last?->code, ['fully_received', 'cancelled'], true);
        $denominator = 0;
        $numerator = 0;
        foreach ($durations as $stage => $seconds) {
            if (! array_key_exists($stage, $policy->stageSlaSeconds)) {
                continue;
            }

            $denominator++;
            if ($seconds <= $policy->stageSlaSeconds[$stage]) {
                $numerator++;
            }
        }

        return new ProcurementCycleMetric(
            stageDurationSeconds: $durations,
            totalDurationSeconds: array_sum($durations),
            slaNumerator: $numerator,
            slaDenominator: $denominator,
            closed: $closed,
        );
    }

    private function businessSeconds(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ProcurementCyclePolicy $policy,
    ): int {
        if ($start == $end) {
            return 0;
        }

        $timezone = new DateTimeZone($policy->timezone);
        $localStart = $start->setTimezone($timezone);
        $localEnd = $end->setTimezone($timezone);
        $cursor = $localStart->setTime(0, 0);
        $lastDay = $localEnd->setTime(0, 0);
        $seconds = 0;

        while ($cursor <= $lastDay) {
            if (in_array((int) $cursor->format('N'), $policy->businessWeekdays, true)) {
                $windowStart = new DateTimeImmutable(
                    $cursor->format('Y-m-d').'T'.$policy->businessDayStart,
                    $timezone,
                );
                $windowEnd = new DateTimeImmutable(
                    $cursor->format('Y-m-d').'T'.$policy->businessDayEnd,
                    $timezone,
                );
                if ($windowEnd <= $windowStart) {
                    throw new DomainException('Business calendar day end must be later than start.');
                }

                $intersectionStart = $localStart > $windowStart ? $localStart : $windowStart;
                $intersectionEnd = $localEnd < $windowEnd ? $localEnd : $windowEnd;
                if ($intersectionEnd > $intersectionStart) {
                    $seconds += $intersectionEnd->getTimestamp() - $intersectionStart->getTimestamp();
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $seconds;
    }
}
