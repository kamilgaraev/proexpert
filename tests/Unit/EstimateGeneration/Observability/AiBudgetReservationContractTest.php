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
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001100_harden_ai_operation_budget_lifecycle.php');
        self::assertIsString($migration);

        self::assertStringContainsString("pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-global'", $migration);
        self::assertStringContainsString("pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-org:'", $migration);
        self::assertStringContainsString('immutable_fingerprint', $migration);
        self::assertStringContainsString('estimate_generation_ai_budget_attempt_conflict', $migration);
        self::assertStringContainsString("status IN ('authorized','sent_pending','pending_reconciliation','settled','released','failed')", $migration);
        self::assertStringContainsString('v_global_daily + p_amount > v_global.daily_budget', $migration);
        self::assertStringContainsString('v_global_monthly + p_amount > v_global.monthly_budget', $migration);
        self::assertStringContainsString('v_org_daily + p_amount > v_organization.daily_budget', $migration);
        self::assertStringContainsString('v_org_monthly + p_amount > v_organization.monthly_budget', $migration);
        self::assertStringContainsString('eg_release_ai_budget', $migration);
        self::assertStringContainsString('eg_mark_ai_budget_sent', $migration);
        self::assertStringContainsString('eg_reconcile_expired_ai_budgets', $migration);
        self::assertStringContainsString("status = 'settled' AND currency = p_currency AND actual_amount = p_actual", $migration);
    }

    #[Test]
    public function hash_backfill_never_disables_snapshot_immutability(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000950_canonicalize_settings_snapshot_hashes.php');
        self::assertIsString($migration);

        self::assertStringContainsString('estimate_generation_setting_snapshot_hashes', $migration);
        self::assertStringNotContainsString('DROP TRIGGER', $migration);
        self::assertStringNotContainsString("->update(['snapshot_hash'", $migration);
    }
}
