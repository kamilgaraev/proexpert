<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Services;

use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaPauseWindow;
use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class CustomerSlaClock
{
    public function elapsedBusinessSeconds(
        CarbonImmutable $from,
        CarbonImmutable $to,
        CustomerSlaPolicy $policy,
        iterable $pauseWindows,
    ): int {
        if ($to < $from) {
            throw new InvalidArgumentException('customer_sla_interval_invalid');
        }
        if ($to->equalTo($from)) {
            return 0;
        }

        $pauses = $this->normalizePauses($pauseWindows, $from, $to);
        $cursor = $from->setTimezone($policy->timezone)->startOfDay();
        $lastDay = $to->setTimezone($policy->timezone)->startOfDay();
        $seconds = 0;

        while ($cursor <= $lastDay) {
            $date = $cursor->toDateString();
            if (!in_array($date, $policy->holidays, true)) {
                foreach ($policy->weekdayIntervals[$cursor->dayOfWeekIso] ?? [] as $interval) {
                    $opens = CarbonImmutable::createFromFormat(
                        'Y-m-d H:i',
                        $date.' '.$interval['opens'],
                        $policy->timezone,
                    );
                    $closes = CarbonImmutable::createFromFormat(
                        'Y-m-d H:i',
                        $date.' '.$interval['closes'],
                        $policy->timezone,
                    );
                    if (!$opens instanceof CarbonImmutable || !$closes instanceof CarbonImmutable) {
                        throw new InvalidArgumentException('customer_sla_calendar_interval_invalid');
                    }

                    $start = $opens->greaterThan($from) ? $opens : $from;
                    $end = $closes->lessThan($to) ? $closes : $to;
                    if ($end <= $start) {
                        continue;
                    }

                    $intervalSeconds = (int) $start->diffInSeconds($end);
                    foreach ($pauses as [$pauseStart, $pauseEnd]) {
                        $overlapStart = $pauseStart->greaterThan($start) ? $pauseStart : $start;
                        $overlapEnd = $pauseEnd->lessThan($end) ? $pauseEnd : $end;
                        if ($overlapEnd > $overlapStart) {
                            $intervalSeconds -= (int) $overlapStart->diffInSeconds($overlapEnd);
                        }
                    }
                    $seconds += max(0, $intervalSeconds);
                }
            }
            $cursor = $cursor->addDay()->startOfDay();
        }

        return $seconds;
    }

    private function normalizePauses(iterable $pauseWindows, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pauses = [];
        foreach ($pauseWindows as $window) {
            if (!$window instanceof CustomerSlaPauseWindow) {
                throw new InvalidArgumentException('customer_sla_pause_window_invalid');
            }
            $start = $window->startsAt->greaterThan($from) ? $window->startsAt : $from;
            $end = $window->endsAt->lessThan($to) ? $window->endsAt : $to;
            if ($end > $start) {
                $pauses[] = [$start, $end];
            }
        }
        usort($pauses, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $merged = [];
        foreach ($pauses as [$start, $end]) {
            $last = array_key_last($merged);
            if ($last === null || $start > $merged[$last][1]) {
                $merged[] = [$start, $end];
                continue;
            }
            if ($end > $merged[$last][1]) {
                $merged[$last][1] = $end;
            }
        }

        return $merged;
    }
}
