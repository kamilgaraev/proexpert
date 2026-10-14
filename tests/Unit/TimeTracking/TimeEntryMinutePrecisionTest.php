<?php

declare(strict_types=1);

namespace Tests\Unit\TimeTracking;

use App\Models\TimeEntry;
use PHPUnit\Framework\TestCase;

final class TimeEntryMinutePrecisionTest extends TestCase
{
    public function test_one_minute_entries_are_not_rounded_before_aggregation(): void
    {
        $entry = new TimeEntry;
        $entry->setRawAttributes(['hours_worked' => '0.02', 'net_minutes' => 1]);
        self::assertSame(1 / 60, $entry->hours_worked);
        self::assertEqualsWithDelta(1.0, array_sum(array_fill(0, 60, $entry->hours_worked)), 0.00000001);
    }

    public function test_microseconds_are_authoritative_over_convenience_decimal_minutes(): void
    {
        $entry = new TimeEntry;
        $entry->setRawAttributes(['hours_worked' => '0.00', 'net_minutes' => '0.0083333333', 'net_microseconds' => 500_000]);
        self::assertSame(500_000 / 3_600_000_000, $entry->hours_worked);
        self::assertEqualsWithDelta(1 / 60, array_sum(array_fill(0, 120, $entry->hours_worked)), 0.0000000001);
    }

    public function test_legacy_hours_only_entries_keep_their_recorded_hours(): void
    {
        $entry = new TimeEntry;
        $entry->setRawAttributes(['hours_worked' => '2.25', 'net_minutes' => null]);
        self::assertSame(2.25, $entry->hours_worked);
    }
}
