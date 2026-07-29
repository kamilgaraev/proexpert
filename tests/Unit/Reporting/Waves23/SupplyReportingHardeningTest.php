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
