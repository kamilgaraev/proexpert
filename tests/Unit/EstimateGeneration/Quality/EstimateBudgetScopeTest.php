<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateBudgetScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateBudgetScopeTest extends TestCase
{
    #[Test]
    public function it_does_not_turn_absent_overhead_and_profit_rules_into_zero_amounts(): void
    {
        $scope = (new EstimateBudgetScope)->project([
            'completeness' => ['status' => 'confirmed_scope_only'],
        ], 3154397.72);

        self::assertSame(3154397.72, $scope['direct_costs']);
        self::assertSame('not_calculated', $scope['overhead']['status']);
        self::assertNull($scope['overhead']['amount']);
        self::assertSame('not_calculated', $scope['profit']['status']);
        self::assertNull($scope['commercial_budget']['amount']);
        self::assertSame('confirmed_scope_only', $scope['claim']);
    }

    #[Test]
    public function it_exposes_a_commercial_budget_only_from_explicit_calculation_results(): void
    {
        $scope = (new EstimateBudgetScope)->project([
            'completeness' => ['status' => 'full_confirmed_scope'],
            'budget_calculation' => [
                'overhead' => ['status' => 'calculated', 'amount' => 100.0],
                'profit' => ['status' => 'calculated', 'amount' => 50.0],
            ],
        ], 1000.0);

        self::assertSame('calculated', $scope['overhead']['status']);
        self::assertSame(100.0, $scope['overhead']['amount']);
        self::assertSame(1150.0, $scope['commercial_budget']['amount']);
        self::assertSame('commercial_budget', $scope['claim']);
    }
}
