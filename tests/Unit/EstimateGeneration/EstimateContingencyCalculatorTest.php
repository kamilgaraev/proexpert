<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\EstimateContingencyCalculator;
use PHPUnit\Framework\TestCase;

final class EstimateContingencyCalculatorTest extends TestCase
{
    public function test_percent_reserve_is_recomputed_and_a_fixed_reserve_is_preserved(): void
    {
        $calculator = new EstimateContingencyCalculator;
        self::assertSame('10.01', $calculator->amount(['contingency_percent' => '10'], '100.05'));
        self::assertSame('20.01', $calculator->amount(['contingency_percent' => '10', 'contingency_amount' => '10.01'], '200.05'));
        self::assertSame('10.01', $calculator->amount(['contingency_kind' => 'fixed', 'contingency_amount' => '10.01', 'contingency_percent' => '10'], '200.05'));
        self::assertSame('0.00', $calculator->amount([], '100.05'));
    }
}
