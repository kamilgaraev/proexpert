<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Support\ExactDecimal;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExactDecimalTest extends TestCase
{
    #[Test]
    public function percentage_remains_exact_above_ieee_754_integer_precision(): void
    {
        self::assertSame(
            '90.07199255',
            ExactDecimal::percentage(9_007_199_254_740_993, 10_000_000_000_000_000, 8),
        );
        self::assertSame(
            '-90.07199255',
            ExactDecimal::percentage(-9_007_199_254_740_993, 10_000_000_000_000_000, 8),
        );
    }

    #[Test]
    public function money_and_labor_cost_are_calculated_without_floating_point(): void
    {
        self::assertSame(12_345, ExactDecimal::minor('123.45'));
        self::assertSame(15_431, ExactDecimal::multiplyMinor(12_345, '1.25'));
        self::assertSame(6_173, ExactDecimal::multiplyMinor(12_345, '0.50'));
    }

    #[Test]
    public function fractional_minor_units_fail_closed(): void
    {
        $this->expectException(DomainException::class);

        ExactDecimal::minor('1.001');
    }
}
