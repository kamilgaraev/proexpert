<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use PHPUnit\Framework\TestCase;

final class PurchaseOrderCreationConcurrencyContractTest extends TestCase
{
    public function test_purchase_request_is_locked_before_existing_order_is_resolved(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/BusinessModules/Features/Procurement/Services/PurchaseOrderService.php');

        self::assertIsString($source);
        $method = substr(
            $source,
            strpos($source, 'public function create('),
            strpos($source, 'private function metadataPdfPath') - strpos($source, 'public function create('),
        );
        self::assertStringContainsString('lockForUpdate()', $method);
        self::assertStringContainsString('return $existingOrder->fresh', $method);
        self::assertLessThan(
            strpos($method, 'purchaseOrders()->first()'),
            strpos($method, 'lockForUpdate()'),
        );
    }

    public function test_database_guard_enforces_one_live_order_per_request(): void
    {
        $migration = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/Procurement/migrations/2026_03_18_120000_add_procurement_uniqueness_guards.php'
        );

        self::assertIsString($migration);
        self::assertStringContainsString('purchase_orders_purchase_request_unique', $migration);
        self::assertStringContainsString('purchase_request_id', $migration);
    }
}
