<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorOrganizationExpenseBudget;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached;
use PHPUnit\Framework\TestCase;

final class EstimatorOrganizationExpenseBudgetTest extends TestCase
{
    public function test_budget_currency_precision_and_daily_monthly_order_are_validated(): void
    {
        self::assertSame(['daily' => '120000', 'monthly' => '150000'], EstimatorOrganizationExpenseBudget::limitsMicroRub(['daily' => '0.12', 'monthly' => '0.15', 'currency' => 'RUB']));
        foreach ([['daily' => '2.00', 'monthly' => '1.00', 'currency' => 'RUB'], ['daily' => '1', 'monthly' => '2', 'currency' => 'USD'],
            ['daily' => '0', 'monthly' => '2', 'currency' => 'RUB'], ['daily' => '0.001', 'monthly' => '2', 'currency' => 'RUB']] as $invalid) {
            try {
                EstimatorOrganizationExpenseBudget::limitsMicroRub($invalid);
                self::fail('Invalid supplier budget was accepted');
            } catch (SessionAiCostLimitReached $exception) {
                self::assertSame('estimator_credit_organization_budget_invalid', $exception->reason);
            }
        }
    }
}
