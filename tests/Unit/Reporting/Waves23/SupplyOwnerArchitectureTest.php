<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DrillDown\InventoryRiskDrillDownProvider;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Providers\InventoryRiskReportProvider;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Queries\InventoryRiskRowQuery;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Readiness\InventoryRiskReadinessProbe;
use App\BusinessModules\Features\BasicWarehouse\Reporting\Support\ImmutableReportingRecord as WarehouseImmutableRecord;
use App\BusinessModules\Features\Procurement\Reporting\Award\Providers\SupplierAwardCompetitivenessReportProvider;
use App\BusinessModules\Features\Procurement\Reporting\Award\Queries\SupplierAwardRowQuery;
use App\BusinessModules\Features\Procurement\Reporting\Award\Readiness\SupplierAwardReadinessProbe;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Providers\ProcurementCycleReportProvider;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Queries\ProcurementCycleRowQuery;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Readiness\ProcurementCycleReadinessProbe;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DrillDown\SupplyReliabilityDrillDownProvider;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityBackfillWatermark;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Providers\SupplyReliabilityReportProvider;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Queries\SupplyReliabilityRowQuery;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Readiness\SupplyReliabilityReadinessProbe;
use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord as ProcurementImmutableRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

final class SupplyOwnerArchitectureTest extends TestCase
{
    #[DataProvider('providerContracts')]
    public function test_owner_components_implement_exact_platform_contract(
        string $class,
        string $contract,
    ): void {
        self::assertTrue(is_a($class, $contract, true));
    }

    public static function providerContracts(): array
    {
        return [
            [ProcurementCycleReportProvider::class, ReportDataProvider::class],
            [ProcurementCycleRowQuery::class, ReportRowQuery::class],
            [ProcurementCycleRowQuery::class, ReportDrillDownProvider::class],
            [ProcurementCycleReadinessProbe::class, ReportDefinitionReadinessProbe::class],
            [SupplierAwardCompetitivenessReportProvider::class, ReportDataProvider::class],
            [SupplierAwardRowQuery::class, ReportRowQuery::class],
            [SupplierAwardRowQuery::class, ReportDrillDownProvider::class],
            [SupplierAwardReadinessProbe::class, ReportDefinitionReadinessProbe::class],
            [SupplyReliabilityReportProvider::class, ReportDataProvider::class],
            [SupplyReliabilityRowQuery::class, ReportRowQuery::class],
            [SupplyReliabilityDrillDownProvider::class, ReportDrillDownProvider::class],
            [SupplyReliabilityReadinessProbe::class, ReportDefinitionReadinessProbe::class],
            [InventoryRiskReportProvider::class, ReportDataProvider::class],
            [InventoryRiskRowQuery::class, ReportRowQuery::class],
            [InventoryRiskDrillDownProvider::class, ReportDrillDownProvider::class],
            [InventoryRiskReadinessProbe::class, ReportDefinitionReadinessProbe::class],
        ];
    }

    #[DataProvider('immutableReportingModels')]
    public function test_reporting_fact_snapshot_and_owner_models_are_final_and_immutable(
        string $class,
        string $immutableRecord,
    ): void {
        $reflection = new ReflectionClass($class);
        self::assertTrue($reflection->isFinal(), $class);
        self::assertContains($immutableRecord, class_uses_recursive($class), $class);
    }

    public static function immutableReportingModels(): array
    {
        $procurement = [
            'Award\\Models\\SupplierAwardSnapshot',
            'Award\\Models\\SupplierAwardRow',
            'Award\\Models\\SupplierAwardDecisionVersion',
            'Cycle\\Models\\ProcurementCycleOwnerExpectationVersion',
            'Cycle\\Models\\ProcurementCyclePolicyVersion',
            'Cycle\\Models\\ProcurementCycleRow',
            'Cycle\\Models\\ProcurementCycleSnapshot',
            'Cycle\\Models\\ProcurementProcessEvent',
            'Supply\\Models\\PurchaseOrderPromiseVersion',
            'Supply\\Models\\SentPurchaseOrderLineOwner',
            'Supply\\Models\\SupplyLifecycleEvent',
            'Supply\\Models\\SupplyReliabilitySnapshot',
            'Supply\\Models\\SupplyReliabilityRow',
            'Supply\\Models\\SupplyReliabilityPolicyVersion',
        ];
        $warehouse = [
            'InventoryRisk\\Models\\InventoryReorderPolicyVersion',
            'InventoryRisk\\Models\\InventoryDemandSnapshot',
            'InventoryRisk\\Models\\InventoryRiskRow',
            'InventoryRisk\\Models\\InventoryRiskSnapshot',
            'InventoryRisk\\Models\\WarehouseDailyBalanceSnapshot',
            'InventoryRisk\\Models\\WarehouseDailyBalanceRow',
            'InventoryRisk\\Models\\WarehouseInventoryEvent',
        ];

        return [
            ...array_map(
                static fn (string $suffix): array => [
                    'App\\BusinessModules\\Features\\Procurement\\Reporting\\'.$suffix,
                    ProcurementImmutableRecord::class,
                ],
                $procurement,
            ),
            ...array_map(
                static fn (string $suffix): array => [
                    'App\\BusinessModules\\Features\\BasicWarehouse\\Reporting\\'.$suffix,
                    WarehouseImmutableRecord::class,
                ],
                $warehouse,
            ),
        ];
    }

    public function test_backfill_watermark_is_explicitly_mutable_operational_state(): void
    {
        $reflection = new ReflectionClass(SupplyReliabilityBackfillWatermark::class);

        self::assertTrue($reflection->isFinal());
        self::assertNotContains(
            ProcurementImmutableRecord::class,
            class_uses_recursive(SupplyReliabilityBackfillWatermark::class),
        );
        self::assertSame(
            'supply_reliability_backfill_watermarks',
            (new SupplyReliabilityBackfillWatermark)->getTable(),
        );
    }

    public function test_inventory_reporting_never_uses_ambiguous_price_field(): void
    {
        $path = dirname(__DIR__, 4).'/app/BusinessModules/Features/BasicWarehouse/Reporting';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            self::assertStringNotContainsString('price_per_unit', $contents, $file->getPathname());
        }
    }

    public function test_owner_migrations_install_append_only_fences(): void
    {
        $paths = [
            dirname(__DIR__, 4).'/app/BusinessModules/Features/Procurement/migrations/2026_07_26_050000_create_procurement_cycle_reporting_tables.php',
            dirname(__DIR__, 4).'/app/BusinessModules/Features/Procurement/migrations/2026_07_26_050100_create_supplier_award_reporting_tables.php',
            dirname(__DIR__, 4).'/app/BusinessModules/Features/Procurement/migrations/2026_07_26_120000_create_supply_reliability_reporting_tables.php',
            dirname(__DIR__, 4).'/app/BusinessModules/Features/BasicWarehouse/migrations/2026_07_26_130000_create_inventory_risk_reporting_tables.php',
        ];
        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringContainsString('_append_only BEFORE UPDATE OR DELETE', $contents, $path);
        }
    }
}
