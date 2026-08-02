<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\DTO;

use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class CustomerSlaPolicy
{
    public function __construct(
        public string $timezone,
        public array $weekdayIntervals,
        public array $holidays,
        public array $pauseStatuses,
        public int $firstResponseTargetSeconds,
        public int $resolutionTargetSeconds,
        public string $version,
    ) {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('customer_sla_policy_invalid', 0, $exception);
        }

        if (
            $firstResponseTargetSeconds < 1
            || $resolutionTargetSeconds < 1
            || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $version) !== 1
            || !$this->validWeekdayIntervals($weekdayIntervals)
            || !$this->validStringList($holidays, '/^\d{4}-\d{2}-\d{2}$/D')
            || !$this->validStringList($pauseStatuses, '/^[a-z][a-z0-9_]{0,63}$/D')
        ) {
            throw new InvalidArgumentException('customer_sla_policy_invalid');
        }
    }

    private function validWeekdayIntervals(array $weekdays): bool
    {
        foreach ($weekdays as $weekday => $intervals) {
            if (!is_int($weekday) || $weekday < 1 || $weekday > 7 || !is_array($intervals) || !array_is_list($intervals)) {
                return false;
            }

            $lastCloses = null;
            foreach ($intervals as $interval) {
                if (
                    !is_array($interval)
                    || array_keys($interval) !== ['opens', 'closes']
                    || !is_string($interval['opens'])
                    || !is_string($interval['closes'])
                    || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $interval['opens']) !== 1
                    || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $interval['closes']) !== 1
                    || $interval['opens'] >= $interval['closes']
                    || ($lastCloses !== null && $interval['opens'] < $lastCloses)
                ) {
                    return false;
                }
                $lastCloses = $interval['closes'];
            }
        }

        return true;
    }

    private function validStringList(array $values, string $pattern): bool
    {
        if (!array_is_list($values) || count($values) !== count(array_unique($values))) {
            return false;
        }
        foreach ($values as $value) {
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                return false;
            }
        }

        return true;
    }
}
