<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\ProjectCommandCenter;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectCommandCenterPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ProjectCommandCenterPeriodTest extends TestCase
{
    public function test_it_resolves_month_and_custom_ranges_to_different_filterable_bounds(): void
    {
        $asOf = CarbonImmutable::parse('2026-07-21T12:00:00+03:00');
        $month = ProjectCommandCenterPeriod::resolve('month', null, null, null, null, $asOf);
        $custom = ProjectCommandCenterPeriod::resolve('custom', '2026-05-01', '2026-05-31', null, null, $asOf);

        self::assertSame('2026-07-01', $month->from?->toDateString());
        self::assertSame('2026-07-31', $month->to?->toDateString());
        self::assertSame('2026-05-01', $custom->from?->toDateString());
        self::assertSame('2026-05-31', $custom->to?->toDateString());
        self::assertNotSame($month->toArray(), $custom->toArray());
    }
}
