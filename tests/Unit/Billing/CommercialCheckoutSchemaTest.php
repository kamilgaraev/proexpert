<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;

class CommercialCheckoutSchemaTest extends TestCase
{
    public function test_migration_defines_isolated_orders_and_payments_with_idempotency_guards(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 3).'/database/migrations/2026_07_14_000002_create_commercial_checkout_tables.php');

        $this->assertIsString($migration);
        $this->assertStringContainsString("Schema::create('commercial_orders'", $migration);
        $this->assertStringContainsString("Schema::create('commercial_payments'", $migration);
        $this->assertStringContainsString("unique(['organization_id', 'client_idempotency_key']", $migration);
        $this->assertStringContainsString('provider_idempotency_key', $migration);
        $this->assertStringContainsString("unique(['id', 'organization_id'], 'commercial_orders_id_org_unique')", $migration);
        $this->assertStringContainsString("foreign(['commercial_account_id', 'organization_id']", $migration);
        $this->assertStringNotContainsString('balance_transaction', $migration);
        $this->assertStringNotContainsString('subscription_plan', $migration);
    }

    public function test_webhook_schema_tracks_idempotent_events_refunds_and_source_entitlements(): void
    {
        $root = dirname(__DIR__, 3);
        $commercial = file_get_contents($root.'/database/migrations/2026_07_14_000001_create_commercial_package_model.php');
        $webhooks = file_get_contents($root.'/database/migrations/2026_07_14_000003_create_commercial_webhook_tables.php');

        $this->assertIsString($commercial);
        $this->assertIsString($webhooks);
        $this->assertStringContainsString("auto_renew_enabled')->default(false)", $commercial);
        $this->assertStringContainsString('source_order_id', $webhooks);
        $this->assertStringContainsString("Schema::create('commercial_refunds'", $webhooks);
        $this->assertStringContainsString("Schema::create('commercial_webhook_events'", $webhooks);
        $this->assertStringContainsString("unique('fingerprint'", $webhooks);
        $this->assertStringContainsString('refunded_amount_minor', $webhooks);
        $this->assertStringContainsString("foreign(['commercial_payment_id', 'commercial_order_id']", $webhooks);
        $this->assertStringContainsString('->restrictOnDelete()', $webhooks);
        $this->assertStringNotContainsString('->nullOnDelete()', $webhooks);
    }

    public function test_renewal_schema_supports_multiple_attempts_and_one_grace_cycle_per_period(): void
    {
        $root = dirname(__DIR__, 3);
        $commercial = file_get_contents($root.'/database/migrations/2026_07_14_000001_create_commercial_package_model.php');
        $checkout = file_get_contents($root.'/database/migrations/2026_07_14_000002_create_commercial_checkout_tables.php');

        $this->assertIsString($commercial);
        $this->assertIsString($checkout);
        $this->assertStringContainsString('saved_payment_method_id', $commercial);
        $this->assertStringContainsString('grace_ends_at', $commercial);
        $this->assertStringContainsString("Schema::create('commercial_renewal_cycles'", $checkout);
        $this->assertStringContainsString("unique(['commercial_account_id', 'target_period_start_at']", $checkout);
        $this->assertStringContainsString("enum('kind', ['purchase', 'renewal'])", $checkout);
        $this->assertStringContainsString("enum('role', ['initial', 'renewal'])", $checkout);
        $this->assertStringContainsString('attempt_number', $checkout);
        $this->assertStringNotContainsString("foreignId('commercial_order_id')->unique()", $checkout);
        $this->assertStringContainsString("date('billing_due_date')", $checkout);
        $this->assertStringContainsString("unique('commercial_order_id'", $checkout);
        $this->assertStringContainsString('commercial_renewal_order_account_tenant_fk', $checkout);
        $this->assertStringContainsString('commercial_payment_role_cycle_check', $checkout);
    }

    public function test_commercial_renewal_schedule_is_registered_once_at_three_moscow_time(): void
    {
        $schedule = file_get_contents(dirname(__DIR__, 3).'/routes/console.php');
        $this->assertIsString($schedule);
        $this->assertSame(1, substr_count($schedule, "Schedule::command('commercial:process-renewals --limit=100')"));
        $this->assertStringContainsString("->dailyAt('03:00')", $schedule);
        $this->assertStringContainsString("->timezone('Europe/Moscow')", $schedule);
        $this->assertStringContainsString('->withoutOverlapping(120)', $schedule);
        $this->assertStringContainsString('->onOneServer()', $schedule);
    }

    public function test_trial_lifecycle_has_separate_hourly_schedule(): void
    {
        $schedule = file_get_contents(dirname(__DIR__, 3).'/routes/console.php');
        $this->assertIsString($schedule);
        $this->assertSame(1, substr_count($schedule, "Schedule::command('commercial:process-trial-lifecycle')"));
        $this->assertStringContainsString("Schedule::command('commercial:process-trial-lifecycle')\n    ->hourly()", $schedule);
    }
}
