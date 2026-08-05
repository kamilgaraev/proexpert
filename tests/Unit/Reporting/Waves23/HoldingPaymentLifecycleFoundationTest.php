<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HoldingPaymentLifecycleFoundationTest extends TestCase
{
    #[Test]
    public function source_capture_is_append_only_and_covers_refunds_with_signed_amounts(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/database/migrations/2026_08_05_025000_create_holding_payment_lifecycle_evidence.php');

        self::assertIsString($migration);
        self::assertStringContainsString('holding_payment_event_coverage_checkpoints', $migration);
        self::assertStringContainsString('holding_payment_transaction_event_versions', $migration);
        self::assertStringContainsString('captured_count', $migration);
        self::assertStringContainsString('gap_count', $migration);
        self::assertStringContainsString("status IN ('completed', 'refunded')", $migration);
        self::assertStringContainsString("OLD.status IN ('completed', 'refunded')", $migration);
        self::assertStringContainsString("NEW.status IN ('completed', 'refunded')", $migration);
        self::assertStringContainsString("TG_OP IN ('UPDATE', 'DELETE')", $migration);
        self::assertStringContainsString("TG_OP IN ('INSERT', 'UPDATE')", $migration);
        self::assertStringContainsString('transaction_row.amount', $migration);
        self::assertStringContainsString('active_value', $migration);
        self::assertStringContainsString('history_complete', $migration);
        self::assertStringContainsString('transaction_row.organization_id = document_organization_id_value', $migration);
        self::assertStringContainsString('transaction_row.organization_id = contract_organization_id_value', $migration);
        self::assertStringContainsString('transaction_row.project_id = document_project_id_value', $migration);
        self::assertStringContainsString('transaction_row.project_id = contract_project_id_value', $migration);
        self::assertStringContainsString('sha256', $migration);
        self::assertStringContainsString('BEFORE UPDATE OR DELETE', $migration);
        self::assertStringContainsString(
            'AFTER UPDATE OF invoiceable_type, invoiceable_id, organization_id, project_id',
            $migration,
        );
        self::assertStringContainsString('BEFORE DELETE ON payment_documents', $migration);
        self::assertStringContainsString('AFTER DELETE OR UPDATE OF organization_id, project_id ON contracts', $migration);
        self::assertStringNotContainsString('PaymentDocumentPaid', $migration);
    }

    #[Test]
    public function source_tables_are_locked_and_capture_is_installed_before_checkpoint_backfill(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/database/migrations/2026_08_05_025000_create_holding_payment_lifecycle_evidence.php');

        self::assertIsString($migration);
        $lock = strpos(
            $migration,
            'LOCK TABLE contracts, payment_documents, payment_transactions IN SHARE ROW EXCLUSIVE MODE',
        );
        $capture = strpos($migration, 'CREATE TRIGGER most_capture_holding_payment_transaction_v1');
        $backfill = strpos($migration, "DO $$\nDECLARE\n    checkpoint_at");

        self::assertIsInt($lock);
        self::assertIsInt($capture);
        self::assertIsInt($backfill);
        self::assertLessThan($capture, $lock);
        self::assertLessThan($backfill, $capture);
    }

    #[Test]
    public function payment_source_models_are_immutable_and_strictly_typed(): void
    {
        $event = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Models/HoldingPaymentTransactionEventVersion.php');
        $checkpoint = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Models/HoldingPaymentEventCoverageCheckpoint.php');

        self::assertIsString($event);
        self::assertIsString($checkpoint);
        self::assertStringContainsString('declare(strict_types=1);', $event);
        self::assertStringContainsString("'amount' => 'decimal:2'", $event);
        self::assertStringContainsString("'recognized_at' => 'immutable_datetime'", $event);
        self::assertStringContainsString('holding_payment_event_immutable', $event);
        self::assertStringContainsString('holding_payment_coverage_immutable', $checkpoint);
    }
}
