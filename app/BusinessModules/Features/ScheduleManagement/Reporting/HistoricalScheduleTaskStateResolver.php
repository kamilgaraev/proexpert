<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Features\ScheduleManagement\Reporting\DTO\HistoricalScheduleTaskState;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class HistoricalScheduleTaskStateResolver
{
    public function at(array $states, DateTimeImmutable $asOf): HistoricalScheduleTaskState
    {
        $eligible = [];
        foreach ($states as $state) {
            if (! $state instanceof HistoricalScheduleTaskState) {
                throw new InvalidArgumentException('historical_schedule_task_state_invalid');
            }
            if ($state->effectiveAt <= $asOf) {
                $eligible[] = $state;
            }
        }
        usort(
            $eligible,
            static fn (HistoricalScheduleTaskState $left, HistoricalScheduleTaskState $right): int => $left->effectiveAt <=> $right->effectiveAt,
        );

        return $eligible[array_key_last($eligible)]
            ?? throw new InvalidArgumentException('historical_schedule_task_state_unavailable');
    }
}
