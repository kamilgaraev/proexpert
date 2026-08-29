<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Scheduling;

final class WorkforceWeekPattern
{
    /** @return array<string, mixed> */
    public static function hoursByIsoWeekday(?string $scheduleType, mixed $weekPattern, mixed $hoursPerDay): array
    {
        if (is_string($weekPattern)) {
            $weekPattern = json_decode($weekPattern, true);
        }

        if (is_array($weekPattern) && array_key_exists('work_days', $weekPattern)) {
            return self::fromWorkDays($weekPattern['work_days'], $hoursPerDay);
        }

        if (is_array($weekPattern) && $weekPattern !== []) {
            return self::fromHoursByWeekday($weekPattern);
        }

        $workDays = match ($scheduleType) {
            null, '', 'weekly', 'five_two' => [1, 2, 3, 4, 5],
            'six_one' => [1, 2, 3, 4, 5, 6],
            'daily' => [1, 2, 3, 4, 5, 6, 7],
            default => null,
        };

        return $workDays === null ? [] : self::fromWorkDays($workDays, $hoursPerDay);
    }

    /** @return array<string, mixed> */
    private static function fromWorkDays(mixed $workDays, mixed $hoursPerDay): array
    {
        if (! is_array($workDays) || $workDays === [] || $hoursPerDay === null || $hoursPerDay === '') {
            return [];
        }

        $normalized = [];
        foreach ($workDays as $workDay) {
            $weekday = filter_var($workDay, FILTER_VALIDATE_INT);
            if ($weekday === false || $weekday < 1 || $weekday > 7 || isset($normalized[$weekday])) {
                return [];
            }

            $normalized[$weekday] = true;
        }

        $pattern = [];
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $pattern[(string) $weekday] = isset($normalized[$weekday]) ? $hoursPerDay : 0;
        }

        return $pattern;
    }

    /**
     * @param array<array-key, mixed> $weekPattern
     * @return array<string, mixed>
     */
    private static function fromHoursByWeekday(array $weekPattern): array
    {
        $pattern = [];
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $key = (string) $weekday;
            if (! array_key_exists($key, $weekPattern) && ! array_key_exists($weekday, $weekPattern)) {
                return [];
            }

            $pattern[$key] = $weekPattern[$key] ?? $weekPattern[$weekday];
        }

        return $pattern;
    }
}
