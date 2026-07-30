<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class SupplyReportingHardeningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 4);
    }

    public function test_owner_export_uses_nullable_safe_keyset_without_offset(): void
    {
        $source = $this->source('app/Support/Reporting/EloquentOwnerReportRows.php');

        self::assertStringNotContainsString('->offset(', $source);
        self::assertStringContainsString('applyPosition(', $source);
        self::assertStringContainsString('orWhereNull($sort->field)', $source);
        self::assertStringContainsString('CASE WHEN {$sort->field} IS NULL', $source);
    }

    public function test_real_mutation_services_depend_on_reporting_recorders(): void
    {
        foreach ([
            'app/BusinessModules/Features/Procurement/Services/PurchaseRequestService.php',
            'app/BusinessModules/Features/Procurement/Services/SupplierRequestService.php',
            'app/BusinessModules/Features/Procurement/Services/SupplierProposalService.php',
            'app/BusinessModules/Features/Procurement/Services/SupplierProposalComparisonService.php',
            'app/BusinessModules/Features/Procurement/Services/PurchaseOrderService.php',
        ] as $file) {
            self::assertStringContainsString('reportingLifecycle', $this->source($file), $file);
        }
        self::assertStringContainsString(
            'WarehouseInventoryEventRecorder',
            $this->source('app/BusinessModules/Features/BasicWarehouse/Services/WarehouseService.php'),
        );
    }

    public function test_backfills_are_bounded_resumable_and_queue_only(): void
    {
        foreach ([
            'Procurement/Reporting/Cycle/Backfill/RunProcurementCycleBackfillSliceJob.php',
            'Procurement/Reporting/Award/Backfill/RunSupplierAwardBackfillSliceJob.php',
            'Procurement/Reporting/Supply/Backfill/RunSupplyReliabilityBackfillSliceJob.php',
            'BasicWarehouse/Reporting/InventoryRisk/Backfill/RunInventoryRiskBackfillSliceJob.php',
        ] as $suffix) {
            $source = $this->source('app/BusinessModules/Features/'.$suffix);
            self::assertStringContainsString('ShouldBeUnique', $source);
            self::assertStringContainsString('reports-source-backfill', $source);
            self::assertStringContainsString('500', $source);
            self::assertStringContainsString('nextCursor', $source);
        }
    }

    public function test_inventory_backfill_uses_canonical_movement_date_and_id_cursor(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Backfill/'
            .'InventoryRiskBackfill.php',
        );

        self::assertStringContainsString("->orderBy('movement_date')", $source);
        self::assertStringContainsString("'movement_date' =>", $source);
        self::assertStringContainsString("->where('id', '>', \$position['id'])", $source);
    }

    public function test_single_receipt_can_close_both_first_and_full_cycle_events(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/ProcurementReportingLifecycleRecorder.php',
        );

        self::assertStringContainsString("\$eventCodes[] = 'first_receipt'", $source);
        self::assertStringContainsString("\$eventCodes[] = 'fully_received'", $source);
        self::assertStringContainsString('foreach ($eventCodes as $eventCode)', $source);
    }

    public function test_inventory_transfer_pair_constraint_requires_exactly_two_balanced_events(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/migrations/'
            .'2026_07_26_130000_create_inventory_risk_reporting_tables.php',
        );

        self::assertStringContainsString('pair_count <> 2', $source);
        self::assertStringContainsString('on_hand_sum <> 0', $source);
        self::assertStringContainsString('unit_dimension = NEW.unit_dimension', $source);
        self::assertStringContainsString('conversion_version = NEW.conversion_version', $source);
    }

    public function test_supply_backfill_requires_explicit_unit_and_posted_timestamp_evidence(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Backfill/'
            .'SupplyReliabilityBackfill.php',
        );

        self::assertStringNotContainsString("'unit-code:'.hash", $source);
        self::assertStringNotContainsString('->endOfDay()', $source);
        self::assertStringContainsString("'reporting_unit_dimension'", $source);
        self::assertStringContainsString("'reporting_conversion_version'", $source);
        self::assertStringContainsString("'reporting_posted_at'", $source);
        self::assertStringContainsString("'reporting_return_events'", $source);
    }

    public function test_receipt_correction_has_a_production_owner_path(): void
    {
        $service = $this->source(
            'app/BusinessModules/Features/Procurement/Services/PurchaseOrderService.php',
        );
        $lifecycle = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/ProcurementReportingLifecycleRecorder.php',
        );

        self::assertStringContainsString('public function reverseReceiptLine(', $service);
        self::assertStringContainsString('receiptReversed(', $service);
        self::assertStringContainsString('public function receiptReversed(', $lifecycle);
        self::assertStringContainsString('$this->supplyEvents->reversal(', $lifecycle);
    }

    public function test_receipt_reversal_api_is_authorized_and_controller_stays_thin(): void
    {
        $routes = $this->source('app/BusinessModules/Features/Procurement/routes.php');
        $controller = $this->source(
            'app/BusinessModules/Features/Procurement/Http/Controllers/PurchaseOrderController.php',
        );
        $method = substr($controller, strpos($controller, 'public function reverseReceiptLine('));
        $method = substr($method, 0, strpos($method, 'public function receiptDocumentPdf('));

        self::assertStringContainsString(
            "Route::post('/{id}/receipt-lines/{line}/reverse'",
            $routes,
        );
        self::assertStringContainsString(
            "middleware('authorize:procurement.purchase_orders.receive')",
            $routes,
        );
        self::assertStringContainsString('$this->service->reverseReceiptLine(', $method);
        self::assertStringNotContainsString('::query()', $method);
        self::assertStringContainsString('AdminResponse::success(', $method);
    }

    public function test_receipt_reversal_service_owns_inventory_lifecycle_and_audit(): void
    {
        $service = $this->source(
            'app/BusinessModules/Features/Procurement/Services/PurchaseOrderService.php',
        );
        $method = substr($service, strpos($service, 'public function reverseReceiptLine('));
        $method = substr($method, 0, strpos($method, 'public function createContractFromOrder('));

        self::assertStringContainsString("->where('organization_id', \$organizationId)", $method);
        self::assertStringContainsString("->where('purchase_order_id', \$purchaseOrderId)", $method);
        self::assertStringContainsString('->lockForUpdate()', $method);
        self::assertStringContainsString('$this->receiptInventory->reverse(', $method);
        self::assertStringNotContainsString('->writeOffAsset(', $method);
        self::assertStringContainsString('$this->reportingLifecycle->receiptReversed(', $method);
        self::assertStringContainsString('$this->auditService->record(', $method);
        self::assertStringContainsString("'reversed_at' => \$occurredAt", $method);
        self::assertStringContainsString("'reversal_idempotency_key' => \$idempotencyKey", $method);
    }

    public function test_receipt_reversal_uses_immutable_linked_inventory_lot(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Services/PurchaseReceiptInventoryService.php',
        );

        self::assertStringContainsString("->where('purchase_receipt_line_id', \$line->id)", $source);
        self::assertStringContainsString("->where('batch_number', 'purchase-receipt-line:'.\$line->id)", $source);
        self::assertStringContainsString('->lockForUpdate()', $source);
        self::assertStringNotContainsString('writeOffAsset(', $source);
    }

    public function test_supply_exports_delegate_to_project_scoped_keyset_cursor(): void
    {
        foreach ([
            'Procurement/Reporting/Supply/Queries/SupplyReliabilityRowQuery.php',
            'BasicWarehouse/Reporting/InventoryRisk/Queries/InventoryRiskRowQuery.php',
        ] as $suffix) {
            $query = $this->source('app/BusinessModules/Features/'.$suffix);
            $cursor = substr($query, strpos($query, 'public function cursor('));

            self::assertStringContainsString('$this->rows->cursor(', $cursor, $suffix);
            self::assertStringContainsString("'project_id'", $cursor, $suffix);
            self::assertStringNotContainsString('offset(', $cursor, $suffix);
        }
    }

    public function test_readiness_is_bound_to_requested_project_scope(): void
    {
        foreach ([
            'Procurement/Reporting/Cycle/Readiness/ProcurementCycleReadinessProbe.php',
            'Procurement/Reporting/Award/Readiness/SupplierAwardReadinessProbe.php',
            'Procurement/Reporting/Supply/Readiness/SupplyReliabilityReadinessProbe.php',
            'BasicWarehouse/Reporting/InventoryRisk/Readiness/InventoryRiskReadinessProbe.php',
        ] as $suffix) {
            $source = $this->source('app/BusinessModules/Features/'.$suffix);
            self::assertStringContainsString('$context->scope->projectIds', $source, $suffix);
            self::assertStringContainsString('whereIn', $source, $suffix);
        }
    }

    public function test_inventory_opening_reconciliation_is_project_bound(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Backfill/'
            .'InventoryRiskBackfill.php',
        );

        self::assertStringContainsString('OrganizationWarehouse::query()', $source);
        self::assertStringContainsString('$warehouseProjectId !== $projectId', $source);
        self::assertStringNotContainsString(
            "WarehouseBalance::query()\n            ->where('project_id'",
            $source,
        );
    }

    public function test_project_delivery_uses_two_atomic_transfer_legs(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/BasicWarehouse/Services/ProjectWarehouseService.php',
        );

        self::assertSame(2, substr_count($source, '$this->warehouseService->transferAsset('));
        self::assertStringContainsString("'purpose' => 'project_delivery_transit'", $source);
        self::assertStringContainsString("'movement' => \$movement->refresh()", $source);
        self::assertStringContainsString("return \$result['movement_in'];", $source);
    }

    public function test_proposal_versions_have_application_and_database_immutability_fences(): void
    {
        $model = $this->source(
            'app/BusinessModules/Features/Procurement/Models/SupplierProposalVersion.php',
        );
        $migration = $this->source(
            'app/BusinessModules/Features/Procurement/migrations/'
            .'2026_07_26_050100_create_supplier_award_reporting_tables.php',
        );
        $legacyMigration = $this->source(
            'app/BusinessModules/Features/Procurement/migrations/'
            .'2026_05_03_000002_create_supplier_proposal_versions.php',
        );

        self::assertStringContainsString('static::updating', $model);
        self::assertStringContainsString('static::deleting', $model);
        self::assertStringContainsString("'supplier_proposal_versions'", $migration);
        self::assertStringNotContainsString('chunkById', $legacyMigration);
    }

    private function source(string $path): string
    {
        $source = file_get_contents($this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
        self::assertIsString($source, $path);

        return $source;
    }
}
