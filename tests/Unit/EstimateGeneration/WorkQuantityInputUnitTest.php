<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkQuantityInputUnit;
use PHPUnit\Framework\TestCase;

final class WorkQuantityInputUnitTest extends TestCase
{
    public function test_scaled_normative_unit_is_kept_as_an_explicit_factor_when_writing_a_base_fact(): void
    {
        self::assertSame(['unit' => 'm2', 'factor' => '100'], WorkQuantityInputUnit::parse('100 м2'));
        self::assertSame(['value' => '125', 'unit' => 'm2'], WorkQuantityInputUnit::normalize('1.25', '100 м2'));
        self::assertSame(['value' => '0.0002', 'unit' => 'm2'], WorkQuantityInputUnit::normalize('2', 'cm2'));
        self::assertSame(['value' => '2000', 'unit' => 'kg'], WorkQuantityInputUnit::normalize('2', 't'));
    }

    public function test_unknown_dimensions_and_zero_multiplier_are_rejected(): void
    {
        self::assertNull(WorkQuantityInputUnit::parse('0 м2'));
        self::assertNull(WorkQuantityInputUnit::parse('тыс. 0 м2'));
        self::assertNull(WorkQuantityInputUnit::parse('unsupported-unit'));
    }
}
