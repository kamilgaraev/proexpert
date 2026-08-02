<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Features\ScheduleManagement\Reporting\DTO\BaselineScheduleTaskSource;
use App\BusinessModules\Features\ScheduleManagement\Reporting\DTO\BaselineScheduleVarianceRow;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BaselineScheduleVarianceSourceTest extends TestCase
{
    public function test_variances_use_pinned_baseline_and_persisted_float(): void
    {
        $row = BaselineScheduleVarianceRow::fromSource(new BaselineScheduleTaskSource(
            taskId: 7,
            baselineStart: new DateTimeImmutable('2026-07-01'),
            baselineEnd: new DateTimeImmutable('2026-07-10'),
            plannedStart: new DateTimeImmutable('2026-07-03'),
            plannedEnd: new DateTimeImmutable('2026-07-13'),
            baselineDurationDays: 9,
            plannedDurationDays: 10,
            totalFloatDays: -2,
            freeFloatDays: -1,
            critical: true,
            status: 'in_progress',
        ), new DateTimeImmutable('2026-07-11'));

        self::assertSame(2, $row->startVarianceDays);
        self::assertSame(3, $row->endVarianceDays);
        self::assertSame(1, $row->durationVarianceDays);
        self::assertSame(-2, $row->totalFloatDays);
        self::assertTrue($row->critical);
        self::assertFalse($row->overdue);
    }

    public function test_missing_baseline_is_unknown_and_completed_task_is_never_overdue(): void
    {
        $missing = BaselineScheduleVarianceRow::fromSource(new BaselineScheduleTaskSource(
            7,
            null,
            null,
            new DateTimeImmutable('2026-07-01'),
            new DateTimeImmutable('2026-07-02'),
            null,
            1,
            0,
            0,
            false,
            'planned',
        ), new DateTimeImmutable('2026-07-03'));
        $completed = BaselineScheduleVarianceRow::fromSource(new BaselineScheduleTaskSource(
            8,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-10'),
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-07-01'),
            9,
            30,
            0,
            0,
            false,
            'completed',
        ), new DateTimeImmutable('2026-07-29'));

        self::assertNull($missing->startVarianceDays);
        self::assertNull($missing->durationVarianceDays);
        self::assertContains('SCHEDULE_BASELINE_MISSING', $missing->warningCodes);
        self::assertFalse($completed->overdue);
    }
}
