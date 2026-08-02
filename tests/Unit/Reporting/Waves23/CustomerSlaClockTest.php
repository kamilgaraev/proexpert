<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaPauseWindow;
use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaPolicy;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CustomerSlaClockTest extends TestCase
{
    #[Test]
    public function friday_to_monday_uses_two_business_hours(): void
    {
        $seconds = (new CustomerSlaClock())->elapsedBusinessSeconds(
            CarbonImmutable::parse('2026-07-24T17:00:00+03:00'),
            CarbonImmutable::parse('2026-07-27T10:00:00+03:00'),
            $this->weekdayPolicy(),
            [],
        );

        self::assertSame(7_200, $seconds);
    }

    #[Test]
    public function pause_windows_are_intersected_with_business_time(): void
    {
        $seconds = (new CustomerSlaClock())->elapsedBusinessSeconds(
            CarbonImmutable::parse('2026-07-27T09:00:00+03:00'),
            CarbonImmutable::parse('2026-07-27T12:00:00+03:00'),
            $this->weekdayPolicy(),
            [
                new CustomerSlaPauseWindow(
                    CarbonImmutable::parse('2026-07-27T09:30:00+03:00'),
                    CarbonImmutable::parse('2026-07-27T10:30:00+03:00'),
                ),
            ],
        );

        self::assertSame(7_200, $seconds);
    }

    private function weekdayPolicy(): CustomerSlaPolicy
    {
        return new CustomerSlaPolicy(
            timezone: 'Europe/Moscow',
            weekdayIntervals: [
                1 => [['opens' => '09:00', 'closes' => '18:00']],
                2 => [['opens' => '09:00', 'closes' => '18:00']],
                3 => [['opens' => '09:00', 'closes' => '18:00']],
                4 => [['opens' => '09:00', 'closes' => '18:00']],
                5 => [['opens' => '09:00', 'closes' => '18:00']],
            ],
            holidays: [],
            pauseStatuses: ['waiting_customer'],
            firstResponseTargetSeconds: 14_400,
            resolutionTargetSeconds: 86_400,
            version: 'customer-sla.v1',
        );
    }
}
