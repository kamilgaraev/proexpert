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
}
