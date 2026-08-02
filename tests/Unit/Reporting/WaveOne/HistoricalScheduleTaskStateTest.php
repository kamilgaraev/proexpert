<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Features\ScheduleManagement\Reporting\DTO\HistoricalScheduleTaskState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\HistoricalScheduleTaskStateResolver;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HistoricalScheduleTaskStateTest extends TestCase
{
    #[Test]
    public function state_after_as_of_never_changes_historical_schedule_row(): void
    {
        $resolver = new HistoricalScheduleTaskStateResolver;
        $states = [
            $this->state('2026-07-10T09:00:00+03:00', 'pending', '2026-07-20'),
            $this->state('2026-07-20T09:00:00+03:00', 'completed', '2026-07-25'),
        ];

        $resolved = $resolver->at($states, new DateTimeImmutable('2026-07-15T23:59:59+03:00'));

        self::assertSame('pending', $resolved->status);
        self::assertSame('2026-07-20', $resolved->plannedStart->format('Y-m-d'));
    }

    #[Test]
    public function current_task_data_without_version_evidence_is_not_a_historical_backfill(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new HistoricalScheduleTaskStateResolver)->at(
            [],
            new DateTimeImmutable('2026-07-15T23:59:59+03:00'),
        );
    }

    private function state(string $effectiveAt, string $status, string $plannedStart): HistoricalScheduleTaskState
    {
        return new HistoricalScheduleTaskState(
            taskId: 41,
            projectId: 7,
            scheduleId: 11,
            effectiveAt: new DateTimeImmutable($effectiveAt),
            plannedStart: new DateTimeImmutable($plannedStart),
            plannedEnd: new DateTimeImmutable($plannedStart.' +5 days'),
            plannedDurationDays: 6,
            status: $status,
            taskType: 'task',
            wbsCode: '1.2',
            ownerId: 5,
            contractorId: 9,
            sourceHash: str_repeat('d', 64),
        );
    }
}
