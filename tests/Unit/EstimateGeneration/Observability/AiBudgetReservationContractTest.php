<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiBudgetReservationContractTest extends TestCase
{
    #[Test]
    public function reservation_is_atomic_across_global_and_organization_periods_and_settlement_is_idempotent(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001000_create_ai_budget_reservations.php');
        self::assertIsString($migration);

        self::assertStringContainsString("pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-global'", $migration);
        self::assertStringContainsString("pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-org:'", $migration);
        self::assertStringContainsString('v_global_daily + p_amount > v_global.daily_budget', $migration);
        self::assertStringContainsString('v_global_monthly + p_amount > v_global.monthly_budget', $migration);
        self::assertStringContainsString('v_org_daily + p_amount > v_organization.daily_budget', $migration);
        self::assertStringContainsString('v_org_monthly + p_amount > v_organization.monthly_budget', $migration);
        self::assertStringContainsString('ON CONFLICT (attempt_id) DO NOTHING', $migration);
        self::assertStringContainsString("status = 'settled' AND currency = p_currency AND actual_amount = p_actual", $migration);
    }
}
