<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement;

use App\BusinessModules\Features\WorkforceManagement\Domain\Scheduling\WorkforceWeekPattern;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceWeekPatternTest extends TestCase
{
    #[Test]
    public function weekly_schedule_without_explicit_pattern_defaults_to_five_workdays(): void
    {
        $expected = [
            '1' => 8,
            '2' => 8,
            '3' => 8,
            '4' => 8,
            '5' => 8,
            '6' => 0,
            '7' => 0,
        ];

        self::assertSame($expected, WorkforceWeekPattern::hoursByIsoWeekday('weekly', null, 8));
        self::assertSame($expected, WorkforceWeekPattern::hoursByIsoWeekday('five_two', null, 8));
    }

    #[Test]
    public function explicit_work_days_override_the_weekly_default(): void
    {
        self::assertSame([
            '1' => '10.00',
            '2' => '10.00',
            '3' => '10.00',
            '4' => '10.00',
            '5' => '10.00',
            '6' => '10.00',
            '7' => 0,
        ], WorkforceWeekPattern::hoursByIsoWeekday(
            'weekly',
            ['work_days' => [1, 2, 3, 4, 5, 6]],
            '10.00',
        ));
    }
}
