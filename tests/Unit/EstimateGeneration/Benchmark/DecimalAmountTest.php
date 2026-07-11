<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\DecimalAmount;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DecimalAmountTest extends TestCase
{
    #[Test]
    public function it_adds_large_decimal_costs_without_float_or_integer_overflow(): void
    {
        $amount = new DecimalAmount;
        $amount->add('999999999999.999999999');
        $amount->add('999999999999.999999999');

        self::assertSame('1999999999999.999999998', $amount->value());
    }
}
