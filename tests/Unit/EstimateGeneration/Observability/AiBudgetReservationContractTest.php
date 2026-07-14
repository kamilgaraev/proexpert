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
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001150_enforce_exactly_once_ai_budget_wire_claims.php');
        $lifecycle = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001100_harden_ai_operation_budget_lifecycle.php');
        self::assertIsString($migration);
        self::assertIsString($lifecycle);

        self::assertStringContainsString("pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-global'", $migration);
        self::assertStringContainsString("pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-org:'", $migration);
        self::assertStringContainsString('immutable_fingerprint', $migration);
        self::assertStringContainsString('estimate_generation_ai_budget_attempt_conflict', $migration);
        self::assertStringContainsString('eg_claim_ai_budget_wire', $migration);
        self::assertStringContainsString("status = 'reserved'", $migration);
        self::assertStringContainsString('RETURN false', $migration);
        self::assertStringContainsString('reconciliation_required', $migration);
        self::assertStringContainsString('v_global_daily + p_amount > v_global.daily_budget', $migration);
        self::assertStringContainsString('v_global_monthly + p_amount > v_global.monthly_budget', $migration);
        self::assertStringContainsString('v_org_daily + p_amount > v_organization.daily_budget', $migration);
        self::assertStringContainsString('v_org_monthly + p_amount > v_organization.monthly_budget', $migration);
        self::assertStringContainsString('eg_release_ai_budget', $migration);
        self::assertStringContainsString('eg_reconcile_expired_ai_budgets', $migration);
        self::assertStringContainsString("status = 'settled' AND currency = p_currency AND actual_amount = p_actual", $migration);
        self::assertStringNotContainsString('actual_amount = reservations.reserved_amount', $migration);
        self::assertStringNotContainsString('actual_amount = reserved_amount', $migration);
        self::assertStringNotContainsString('actual_amount = reservations.reserved_amount', $lifecycle);
        self::assertStringNotContainsString('actual_amount = reserved_amount', $lifecycle);
    }

    #[Test]
    public function committed_hash_backfill_is_unchanged_and_forward_side_table_is_append_only(): void
    {
        $legacy = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000950_canonicalize_settings_snapshot_hashes.php');
        $forward = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001125_create_canonical_settings_snapshot_hashes.php');
        self::assertIsString($legacy);
        self::assertIsString($forward);

        self::assertStringContainsString('DROP TRIGGER IF EXISTS eg_setting_snapshot_immutable', $legacy);
        self::assertStringNotContainsString('estimate_generation_setting_snapshot_hashes', $legacy);
        self::assertStringContainsString('estimate_generation_setting_snapshot_hashes', $forward);
        self::assertStringContainsString("if (! Schema::hasTable('estimate_generation_setting_snapshot_hashes'))", $forward);
        self::assertStringContainsString('insertOrIgnore', $forward);
    }

    #[Test]
    public function bounded_reconciliation_is_wired_into_scheduler(): void
    {
        $provider = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');
        $job = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Jobs/ReconcileAiBudgetReservationsJob.php');
        self::assertIsString($provider);
        self::assertIsString($job);

        self::assertStringContainsString('ReconcileAiBudgetReservationsJob', $provider);
        self::assertStringContainsString('everyMinute()', $provider);
        self::assertStringContainsString('reconcileExpired', $job);
        self::assertStringContainsString('min(1000', $job);
    }
}
