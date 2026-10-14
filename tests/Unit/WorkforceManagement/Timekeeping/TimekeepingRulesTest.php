<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Timekeeping;

use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingRules;
use PHPUnit\Framework\TestCase;

final class TimekeepingRulesTest extends TestCase
{
    private const RELATION = ['calendar_profile' => 'ru-five-day', 'weekly_norm_hours' => 40, 'job_share' => 1];

    public function test_shorter_calendar_revision_removes_old_holidays_and_shortened_days(): void
    {
        $rules = TimekeepingRules::snapshot(['calendars' => ['ru-five-day' => ['2026' => ['version' => 'ORG-2026-v2', 'source' => 'Approved organization calendar', 'holidays' => ['2026-09-07'], 'shortened_days' => ['2026-09-04'], 'working_days' => ['2026-09-05']]]]]);

        self::assertSame(480, TimekeepingRules::normMinutes('2026-02-23', self::RELATION, $rules));
        self::assertSame(480, TimekeepingRules::normMinutes('2026-04-30', self::RELATION, $rules));
        self::assertSame(0, TimekeepingRules::normMinutes('2026-09-07', self::RELATION, $rules));
        self::assertSame(420, TimekeepingRules::normMinutes('2026-09-04', self::RELATION, $rules));
        self::assertSame(480, TimekeepingRules::normMinutes('2026-09-05', self::RELATION, $rules));
        self::assertSame($rules, TimekeepingRules::snapshot($rules));
    }

    public function test_explicit_empty_calendar_lists_remain_empty_after_snapshot(): void
    {
        $rules = TimekeepingRules::snapshot(['calendars' => ['ru-five-day' => ['2026' => ['version' => 'ORG-2026-v3', 'source' => 'Approved organization calendar', 'holidays' => [], 'shortened_days' => [], 'working_days' => []]]]]);

        self::assertSame(480, TimekeepingRules::normMinutes('2026-01-09', self::RELATION, $rules));
        self::assertSame(480, TimekeepingRules::normMinutes('2026-04-30', self::RELATION, $rules));
        self::assertFalse(TimekeepingRules::holiday('2026-12-31', self::RELATION, $rules));
        self::assertSame(0, TimekeepingRules::normMinutes('2026-09-05', self::RELATION, $rules));
        self::assertSame($rules, TimekeepingRules::snapshot($rules));
    }
}
