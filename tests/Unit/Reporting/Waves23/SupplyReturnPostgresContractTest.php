<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class SupplyReturnPostgresContractTest extends TestCase
{
    public function test_postgres_contract_pins_return_source_and_cumulative_quantity(): void
    {
        $migration = $this->source(
            'app/BusinessModules/Features/Procurement/migrations/'
            .'2026_07_26_120000_create_supply_reliability_reporting_tables.php',
        );

        foreach ([
            'receipt_return_idempotency_unique',
            'receipt_return_movement_unique',
            'receipt_return_quantity_check',
            'most_purchase_receipt_return_identity_v1',
            "NEW.source_type <> 'warehouse_movement'",
            "source_movement.operation_category <> 'procurement_receipt_return'",
            "source_event.event_type <> 'returned'",
            'source_event.signed_quantity <> -NEW.quantity',
            'NEW.reversed_quantity + NEW.returned_quantity > OLD.original_quantity',
            "'purchase_receipt_returns'",
            'CREATE TRIGGER {$table}_append_only',
        ] as $contract) {
            self::assertStringContainsString($contract, $migration);
        }
    }

    public function test_return_transaction_serializes_retries_and_rejects_changed_payload(): void
    {
        $service = $this->source(
            'app/BusinessModules/Features/Procurement/Services/PurchaseOrderService.php',
        );
        $unitOfWork = $this->source(
            'app/BusinessModules/Features/Procurement/Services/'
            .'DatabasePurchaseReceiptReturnUnitOfWork.php',
        );

        self::assertStringContainsString('pg_advisory_xact_lock', $unitOfWork);
        self::assertStringContainsString("['purchase-receipt-return:'.\$idempotencyKey", $unitOfWork);
        self::assertStringContainsString('CanonicalJson::encode([', $service);
        self::assertStringContainsString('payload_fingerprint', $service);
        self::assertStringContainsString('receipt_return_idempotency_conflict', $service);
        self::assertStringContainsString('}, 3);', $unitOfWork);
    }

    private function source(string $path): string
    {
        $root = dirname(__DIR__, 4).DIRECTORY_SEPARATOR;
        $source = file_get_contents($root.str_replace('/', DIRECTORY_SEPARATOR, $path));
        self::assertIsString($source, $path);

        return $source;
    }
}
