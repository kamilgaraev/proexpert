<?php

declare(strict_types=1);

namespace Tests\Unit\TimeTracking;

use App\BusinessModules\Features\TimeTracking\Support\WorkTimeInterval;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WorkTimeIntervalTest extends TestCase
{
    public function test_legacy_overnight_month_boundary_has_positive_elapsed_minutes(): void
    {
        $interval = WorkTimeInterval::fromInput(['work_date' => '2026-01-31', 'start_time' => '22:00', 'end_time' => '06:00', 'timezone' => 'Europe/Moscow']);
        self::assertSame(480.0, $interval->netMinutes());
        self::assertSame(['2026-01-31' => 120.0, '2026-02-01' => 360.0], $interval->splitByLocalDate());
        self::assertSame('2026-01-31T19:00:00.000000+00:00', $interval->persistence()['start_at']);
        self::assertSame($interval->netMinutes(), WorkTimeInterval::fromInput($interval->persistence())->netMinutes());
    }

    public function test_explicit_break_is_subtracted_from_correct_night_and_local_date(): void
    {
        $interval = WorkTimeInterval::fromInput([
            'work_date' => '2026-01-31', 'start_at' => '2026-01-31T21:00:00+03:00', 'end_at' => '2026-02-01T07:00:00+03:00', 'timezone' => 'Europe/Moscow',
            'break_intervals' => [['start_at' => '2026-01-31T23:30:00+03:00', 'end_at' => '2026-02-01T00:30:00+03:00']],
        ]);
        self::assertSame(540.0, $interval->netMinutes());
        self::assertSame(['2026-01-31' => 150.0, '2026-02-01' => 390.0], $interval->splitByLocalDate());
        self::assertSame(420.0, array_sum(array_column(array_filter($interval->segments(), static fn ($segment) => $segment['night']), 'minutes')));
    }

    public function test_unknown_break_placement_requires_reconciliation_for_legal_daily_fact(): void
    {
        $interval = WorkTimeInterval::fromInput(['timezone' => 'UTC', 'work_date' => '2026-01-31', 'start_time' => '22:00', 'end_time' => '06:00', 'break_time' => 0.5]);
        self::assertSame(450.0, $interval->netMinutes());
        self::assertTrue($interval->requiresReconciliation);
        $this->expectException(InvalidArgumentException::class);
        $interval->splitByLocalDate();
    }

    public function test_dst_uses_elapsed_time_and_authoritative_timezone(): void
    {
        $interval = WorkTimeInterval::fromInput(['work_date' => '2026-03-29', 'start_time' => '01:00', 'end_time' => '04:00', 'timezone' => 'Europe/Berlin']);
        self::assertSame(120.0, $interval->netMinutes());
        self::assertSame(['2026-03-29' => 120.0], $interval->splitByLocalDate());
    }

    public function test_work_date_must_match_timestamp_in_declared_timezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkTimeInterval::fromInput(['work_date' => '2026-01-31', 'start_at' => '2026-01-31T23:00:00Z', 'end_at' => '2026-02-01T04:00:00Z', 'timezone' => 'Europe/Moscow']);
    }

    public function test_overlapping_breaks_cannot_be_counted_twice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkTimeInterval::fromInput(['timezone' => 'UTC', 'work_date' => '2026-01-31', 'start_time' => '08:00', 'end_time' => '12:00', 'break_intervals' => [
            ['start_at' => '2026-01-31T09:00:00Z', 'end_at' => '2026-01-31T10:00:00Z'],
            ['start_at' => '2026-01-31T09:30:00Z', 'end_at' => '2026-01-31T10:30:00Z'],
        ]]);
    }

    public function test_ambiguous_legacy_dst_clock_requires_an_explicit_offset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkTimeInterval::fromInput(['work_date' => '2026-10-25', 'start_time' => '02:30', 'end_time' => '04:00', 'timezone' => 'Europe/Berlin']);
    }

    public function test_explicit_offset_resolves_repeated_dst_clock(): void
    {
        $interval = WorkTimeInterval::fromInput(['work_date' => '2026-10-25', 'start_at' => '2026-10-25T02:30:00+02:00', 'end_at' => '2026-10-25T04:00:00+01:00', 'timezone' => 'Europe/Berlin']);
        self::assertSame(150.0, $interval->netMinutes());
    }

    public function test_iso_fractional_zero_seconds_from_mobile_clients_are_supported(): void
    {
        $interval = WorkTimeInterval::fromInput(['timezone' => 'UTC', 'work_date' => '2026-01-31', 'start_at' => '2026-01-31T08:00:00.000Z', 'end_at' => '2026-01-31T09:00:00.000Z']);
        self::assertSame(60.0, $interval->netMinutes());
        self::assertFalse($interval->requiresReconciliation);
    }

    public function test_non_minute_aligned_timestamp_remains_a_measured_fact(): void
    {
        $interval = WorkTimeInterval::fromInput(['timezone' => 'UTC', 'work_date' => '2026-01-31', 'start_at' => '2026-01-31T08:00:15Z', 'end_at' => '2026-01-31T09:00:15Z']);
        self::assertFalse($interval->requiresReconciliation);
        self::assertSame(3_600_000_000, $interval->netMicroseconds());
        self::assertSame(60.0, $interval->netMinutes());
        self::assertSame(3_600_000_000, array_sum(array_column($interval->segments(), 'microseconds')));
    }

    public function test_fractional_boundaries_and_breaks_are_preserved_without_rounding(): void
    {
        $interval = WorkTimeInterval::fromInput(['timezone' => 'UTC', 'work_date' => '2026-01-31', 'start_at' => '2026-01-31T08:00:00.250Z', 'end_at' => '2026-01-31T08:01:00.750Z', 'break_intervals' => [
            ['start_at' => '2026-01-31T08:00:30.123456Z', 'end_at' => '2026-01-31T08:00:30.623456Z'],
        ]]);
        self::assertFalse($interval->requiresReconciliation);
        self::assertSame('2026-01-31T08:00:00.250000+00:00', $interval->persistence()['start_at']);
        self::assertSame(60_000_000, $interval->netMicroseconds());
        self::assertSame(60_000_000, WorkTimeInterval::fromInput($interval->persistence())->netMicroseconds());
    }

    public function test_fractional_night_boundary_segments_sum_exact_microseconds(): void
    {
        $interval = WorkTimeInterval::fromInput(['timezone' => 'UTC', 'work_date' => '2026-01-31', 'start_at' => '2026-01-31T21:59:59.750Z', 'end_at' => '2026-01-31T22:00:00.250Z']);
        $segments = $interval->segments();
        self::assertSame(500_000, $interval->netMicroseconds());
        self::assertSame(250_000, $segments[0]['microseconds']);
        self::assertFalse($segments[0]['night']);
        self::assertSame(250_000, $segments[1]['microseconds']);
        self::assertTrue($segments[1]['night']);
        self::assertSame(500_000, array_sum(array_column($segments, 'microseconds')));
        self::assertEqualsWithDelta(1.0, $interval->netMicroseconds() * 120 / 60_000_000, 0.0000000001);
    }

    public function test_legacy_default_timezone_remains_uncertain_through_persistence(): void
    {
        $input = ['work_date' => '2026-01-31', 'start_time' => '22:00', 'end_time' => '06:00'];
        $interval = WorkTimeInterval::fromInput($input, 'UTC');
        self::assertSame(480.0, $interval->netMinutes());
        self::assertTrue($interval->timezoneRequiresReconciliation);
        self::assertTrue(WorkTimeInterval::fromInput($interval->persistence())->timezoneRequiresReconciliation);
        self::assertTrue(WorkTimeInterval::normalizeStart($input, 'UTC')['timezone_requires_reconciliation']);
        $this->expectException(InvalidArgumentException::class);
        $interval->segments();
    }

    public function test_confirmed_working_timezone_classifies_legacy_clocks(): void
    {
        $interval = WorkTimeInterval::fromInput(['work_date' => '2026-01-31', 'start_time' => '22:00', 'end_time' => '06:00', 'timezone' => 'Europe/Moscow']);
        self::assertFalse($interval->timezoneRequiresReconciliation);
        self::assertFalse($interval->requiresReconciliation);
        self::assertSame(['2026-01-31' => 120.0, '2026-02-01' => 360.0], $interval->splitByLocalDate());
    }

    public function test_overlap_is_utc_based_and_adjacent_intervals_do_not_overlap(): void
    {
        $first = WorkTimeInterval::fromInput(['work_date' => '2026-01-31', 'start_at' => '2026-01-31T08:00:00+03:00', 'end_at' => '2026-01-31T10:00:00+03:00', 'timezone' => 'Europe/Moscow']);
        $second = WorkTimeInterval::fromInput(['timezone' => 'UTC', 'work_date' => '2026-01-31', 'start_at' => '2026-01-31T06:00:00Z', 'end_at' => '2026-01-31T07:00:00Z']);
        $third = WorkTimeInterval::fromInput(['timezone' => 'UTC', 'work_date' => '2026-01-31', 'start_at' => '2026-01-31T07:00:00Z', 'end_at' => '2026-01-31T08:00:00Z']);
        self::assertTrue($first->overlaps($second));
        self::assertFalse($first->overlaps($third));
    }
}
