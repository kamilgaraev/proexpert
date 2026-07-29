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
    private const EVENT_RANK = [
        'request_created' => 0,
        'request_approved' => 1,
        'solicitation_sent' => 2,
        'supplier_responded' => 3,
        'award_decided' => 4,
        'order_sent' => 5,
        'first_receipt' => 6,
        'fully_received' => 7,
    ];

    private const TERMINAL_CODES = ['fully_received', 'cancelled'];

    public function calculate(
        ProcurementProcessTimeline $timeline,
        ProcurementCyclePolicy $policy,
    ): ProcurementCycleMetric {
        $durations = [];
        $events = $timeline->events;
        if ($events[0]->code !== 'request_created') {
            throw new DomainException('Procurement process must start with request_created.');
        }
        $last = null;
        $seen = [];
        $lastRank = -1;
        $terminalSeen = false;

        foreach ($events as $index => $event) {
            if ($event->occurredAt > $policy->asOf) {
                throw new DomainException('Procurement process event cannot be later than as_of.');
            }
            if ($last !== null && $event->occurredAt < $last->occurredAt) {
                throw new NonMonotonicProcurementTimeline('Procurement process timestamps must be monotonic.');
            }
            if ($terminalSeen) {
                throw new DomainException('Procurement process terminal event must be last.');
            }
            if (isset($seen[$event->code])) {
                throw new DomainException('Procurement process event code must be unique within a timeline.');
            }
            $seen[$event->code] = true;
            if ($event->code !== 'cancelled') {
                $rank = self::EVENT_RANK[$event->code];
                if ($rank <= $lastRank) {
                    throw new DomainException('Procurement process event transition is invalid.');
                }
                $lastRank = $rank;
            }
            $terminalSeen = in_array($event->code, self::TERMINAL_CODES, true);

            $next = $events[$index + 1] ?? null;
            $terminal = in_array($event->code, self::TERMINAL_CODES, true);
            $end = $next?->occurredAt ?? ($terminal ? $event->occurredAt : $policy->asOf);
            if ($end < $event->occurredAt) {
                throw new NonMonotonicProcurementTimeline('Procurement process timestamps must be monotonic.');
            }

            $durations[$event->code] = $this->businessSeconds($event->occurredAt, $end, $policy);
            $last = $event;
        }

        $closed = in_array($last?->code, self::TERMINAL_CODES, true);
        $outcomeAt = $closed ? $last?->occurredAt : null;
        $mature = $outcomeAt !== null
            && $outcomeAt->getTimestamp() + $policy->cohortMaturitySeconds <= $policy->asOf->getTimestamp();
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
            mature: $mature,
            outcomeCode: $closed ? (string) $last?->code : 'open',
            startedAt: $events[0]->occurredAt,
            outcomeAt: $outcomeAt,
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
