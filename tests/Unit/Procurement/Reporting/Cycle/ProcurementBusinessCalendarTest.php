<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementBusinessCalendar;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProcurementBusinessCalendarTest extends TestCase
{
    public function test_counts_only_working_intervals_and_holiday_exceptions(): void
    {
        $policy = $this->policy(
            timezone: 'Europe/Moscow',
            weeklyWindows: [1 => [['09:00', '18:00']], 2 => [['09:00', '18:00']]],
            exceptions: ['2026-08-03' => []],
        );

        $seconds = (new ProcurementBusinessCalendar())->businessSeconds(
            new DateTimeImmutable('2026-08-03T06:00:00+00:00'),
            new DateTimeImmutable('2026-08-04T15:00:00+00:00'),
            $policy,
        );

        self::assertSame(9 * 3600, $seconds);
    }

    public function test_dst_gap_is_counted_as_elapsed_seconds_not_wall_clock_seconds(): void
    {
        $policy = $this->policy(
            timezone: 'Europe/Berlin',
            weeklyWindows: [7 => [['01:00', '04:00']]],
            exceptions: [],
        );

        $seconds = (new ProcurementBusinessCalendar())->businessSeconds(
            new DateTimeImmutable('2026-03-29T00:00:00+00:00'),
            new DateTimeImmutable('2026-03-29T02:00:00+00:00'),
            $policy,
        );

        self::assertSame(2 * 3600, $seconds);
    }

    public function test_zero_or_reversed_interval_is_zero(): void
    {
        $policy = $this->policy('UTC', [1 => [['09:00', '18:00']]], []);
        $calendar = new ProcurementBusinessCalendar();
        $instant = new DateTimeImmutable('2026-08-03T10:00:00+00:00');

        self::assertSame(0, $calendar->businessSeconds($instant, $instant, $policy));
        self::assertSame(0, $calendar->businessSeconds($instant, $instant->modify('-1 second'), $policy));
    }

    private function policy(string $timezone, array $weeklyWindows, array $exceptions): ProcurementCyclePolicyDefinition
    {
        return new ProcurementCyclePolicyDefinition(
            organizationId: 10,
            projectId: null,
            timezone: $timezone,
            weeklyWindows: $weeklyWindows,
            exceptions: $exceptions,
            stageSlaSeconds: [
                'request_approval' => 3600,
                'solicitation' => 3600,
                'supplier_response' => 3600,
                'award' => 3600,
                'order_dispatch' => 3600,
                'first_receipt' => 3600,
                'full_receipt' => 3600,
            ],
            totalSlaSeconds: 86400,
            terminalCancellationPolicy: ['request_rejected'],
            effectiveFrom: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }
}
