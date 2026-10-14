<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

final class TimekeepingRules
{
    public static function snapshot(array $configured = []): array
    {
        $result = array_replace_recursive([
            'version' => 'RU-2026-09-01-v1',
            'verified_at' => '2026-10-14',
            'legal_review_required' => true,
            'overtime_effective_from' => '2026-09-01',
            'overtime_annual_default_minutes' => 7200,
            'overtime_annual_agreement_minutes' => 14400,
            'overtime_daily_minutes' => 240,
            'night_start' => '22:00', 'night_end' => '06:00',
            'night_minimum_multiplier' => 0.2,
            'form' => ['version' => 'MOST-TIME-1', 'approved_basis_reference' => null, 'approved_at' => null],
            'retention' => ['ordinary_years' => 5, 'hazardous_years' => 50, 'pre2003_hazardous_years' => 75, 'approved_basis_reference' => null],
            'calendars' => ['ru-five-day' => ['2026' => [
                'version' => 'RU-1466-2026',
                'source' => 'https://static.government.ru/media/files/4jeB8hNKm69ggOa9yDiYOli6YoAyM21i.pdf',
                'holidays' => ['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09', '2026-02-23', '2026-03-08', '2026-03-09', '2026-05-01', '2026-05-09', '2026-05-11', '2026-06-12', '2026-11-04', '2026-12-31'],
                'shortened_days' => ['2026-04-30', '2026-05-08', '2026-06-11', '2026-11-03'],
                'working_days' => [],
            ]]],
        ], $configured);
        // A calendar revision is complete: cleared or shorter lists must not inherit old dates.
        foreach ($configured['calendars'] ?? [] as $profile => $years) {
            foreach ($years as $year => $calendar) {
                $result['calendars'][$profile][$year] = $calendar;
            }
        }

        return $result;
    }

    public static function normMinutes(string $date, array $relation, array $rules): ?int
    {
        $calendar = $rules['calendars'][$relation['calendar_profile'] ?? ''][substr($date, 0, 4)] ?? null;
        if (! is_array($calendar) || empty($calendar['version']) || empty($calendar['source']) || ! isset($relation['weekly_norm_hours'], $relation['job_share'])) {
            return null;
        }
        $workday = in_array($date, $calendar['working_days'] ?? [], true)
            || ((int) (new \DateTimeImmutable($date))->format('N') <= 5 && ! in_array($date, $calendar['holidays'] ?? [], true));
        if (! $workday) {
            return 0;
        }
        $minutes = (float) $relation['weekly_norm_hours'] * 60 / 5;
        if (in_array($date, $calendar['shortened_days'] ?? [], true)) {
            $minutes = max(0, $minutes - 60);
        }

        // HR stores the effective weekly norm for this contract, including part-time terms.
        return (int) round($minutes);
    }

    public static function holiday(string $date, array $relation, array $rules): bool
    {
        $calendar = $rules['calendars'][$relation['calendar_profile'] ?? ''][substr($date, 0, 4)] ?? [];

        return in_array($date, $calendar['holidays'] ?? [], true);
    }

    public static function nonWorking(string $date, array $relation, array $rules): bool
    {
        return self::normMinutes($date, $relation, $rules) === 0;
    }
}
