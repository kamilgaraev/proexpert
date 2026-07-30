<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Backfill\HistoricalInventoryMovementEvidence;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryRiskGrain;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\ReportingBalanceDay;
use App\Support\Reporting\ReportSourceAccessPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SupplyReportingBehaviorContractTest extends TestCase
{
    public function test_supplier_return_workflow_is_wired_to_inventory_and_reporting(): void
    {
        $root = dirname(__DIR__, 4).DIRECTORY_SEPARATOR;
        $service = file_get_contents($root.'app/BusinessModules/Features/Procurement/Services/PurchaseOrderService.php');
        $inventory = file_get_contents($root.'app/BusinessModules/Features/Procurement/Services/PurchaseReceiptInventoryService.php');
        $lifecycle = file_get_contents($root.'app/BusinessModules/Features/Procurement/Reporting/Supply/Services/SupplyLifecycleEventRecorder.php');
        $controller = file_get_contents($root.'app/BusinessModules/Features/Procurement/Http/Controllers/PurchaseOrderController.php');
        $routes = file_get_contents($root.'app/BusinessModules/Features/Procurement/routes.php');
        $request = file_get_contents($root.'app/BusinessModules/Features/Procurement/Http/Requests/ReturnPurchaseReceiptLineRequest.php');
        $returnModel = file_get_contents($root.'app/BusinessModules/Features/Procurement/Models/PurchaseReceiptReturn.php');
        $migration = file_get_contents($root.'app/BusinessModules/Features/Procurement/migrations/2026_07_26_120000_create_supply_reliability_reporting_tables.php');
        $readiness = file_get_contents($root.'app/BusinessModules/Features/Procurement/Reporting/Supply/Readiness/SupplyReliabilityReadinessProbe.php');

        self::assertStringContainsString('public function returnReceiptLine(', $service);
        self::assertStringContainsString('DB::transaction(', $service);
        self::assertStringContainsString('returnQuantity(', $inventory);
        self::assertStringContainsString("'operation_category' => 'procurement_receipt_return'", $inventory);
        self::assertStringContainsString("'returned'", $lifecycle);
        self::assertStringContainsString('ReturnPurchaseReceiptLineRequest', $controller);
        self::assertStringContainsString('receipt-lines/{line}/return', $routes);
        self::assertStringContainsString('AuthorizationService::class', $request);
        self::assertStringContainsString("'procurement.purchase_orders.receive'", $request);
        self::assertStringContainsString('payload_fingerprint', $service);
        self::assertStringContainsString('pg_advisory_xact_lock', $service);
        self::assertStringContainsString('PurchaseReceiptReturn::query()->create', $service);
        self::assertStringContainsString('returned_quantity', $inventory);
        self::assertStringContainsString('reporting_inventory_project_id', $inventory);
        self::assertStringContainsString('static function (): never', $returnModel);
        self::assertStringContainsString('most_purchase_receipt_return_identity_v1', $migration);
        self::assertStringContainsString('reversed_quantity + returned_quantity <= original_quantity', $migration);
        self::assertStringContainsString('missingReturnLifecycle', $readiness);
        self::assertStringContainsString('missingOrderLifecycle', $readiness);
    }

    public function test_historical_inventory_projection_requires_pinned_unit_identity(): void
    {
        self::assertNull(HistoricalInventoryMovementEvidence::fromMetadata([
            'reporting_source_version' => 1,
        ]));
        self::assertNull(HistoricalInventoryMovementEvidence::fromMetadata([
            'reporting_source_version' => 1,
            'unit_dimension' => 'mass',
            'unit_code' => 'kg',
            'unit_conversion_version' => 'gost-kg-v3',
        ]));

        $evidence = HistoricalInventoryMovementEvidence::fromMetadata([
            'reporting_source_version' => 7,
            'unit_dimension' => 'mass',
            'unit_code' => 'kg',
            'unit_conversion_version' => 'gost-kg-v3',
            'reporting_inventory_project_id' => 10,
            'currency' => 'RUB',
            'currency_source' => 'receipt-line-v2',
        ]);

        self::assertNotNull($evidence);
        self::assertSame(7, $evidence->sourceVersion);
        self::assertSame('mass', $evidence->unitDimension);
        self::assertSame('kg', $evidence->unitCode);
        self::assertSame('gost-kg-v3', $evidence->conversionVersion);
        self::assertSame(10, $evidence->projectId);
    }

    public function test_required_source_kind_is_fail_closed_when_scope_has_other_resources(): void
    {
        $policy = new ReportSourceAccessPolicy;

        self::assertFalse($policy->allows(
            [new ReportScopedResource('project', 10, 10)],
            'purchase_order_item',
            55,
            10,
            [10],
        ));
        self::assertTrue($policy->allows(
            [new ReportScopedResource('purchase_order_item', 55, 10)],
            'purchase_order_item',
            55,
            10,
            [10],
        ));
        self::assertFalse($policy->allows(
            [new ReportScopedResource('purchase_order_item', 55, 11)],
            'purchase_order_item',
            55,
            11,
            [10],
        ));
    }

    public function test_unconstrained_resource_scope_still_requires_project_membership(): void
    {
        $policy = new ReportSourceAccessPolicy;

        self::assertTrue($policy->allows([], 'warehouse', 2, 10, [10]));
        self::assertFalse($policy->allows([], 'warehouse', 2, 11, [10]));
        self::assertFalse($policy->allows([], 'warehouse', 2, null, [10]));
    }

    public function test_resource_filter_ids_are_unconstrained_only_for_empty_scope_and_fail_closed_for_other_kinds(): void
    {
        $policy = new ReportSourceAccessPolicy;

        self::assertNull($policy->allowedIds([], 'warehouse'));
        self::assertSame(
            [2, 5],
            $policy->allowedIds([
                new ReportScopedResource('warehouse', 5, 10),
                new ReportScopedResource('purchase_order_item', 9, 10),
                new ReportScopedResource('warehouse', 2, 10),
                new ReportScopedResource('warehouse', 5, 10),
            ], 'warehouse'),
        );
        self::assertSame(
            [],
            $policy->allowedIds(
                [new ReportScopedResource('project', 10, 10)],
                'warehouse',
            ),
        );
    }

    public function test_daily_balance_uses_report_timezone_at_midnight_boundary(): void
    {
        $occurredAt = new DateTimeImmutable('2026-07-30T21:30:00+00:00');

        self::assertSame(
            '2026-07-31',
            ReportingBalanceDay::resolve($occurredAt, new DateTimeZone('Europe/Moscow')),
        );
        self::assertSame(
            '2026-07-30',
            ReportingBalanceDay::resolve($occurredAt, new DateTimeZone('UTC')),
        );
    }

    public function test_daily_balance_drill_window_is_exact_in_report_timezone(): void
    {
        [$start, $end] = ReportingBalanceDay::utcWindow(
            '2026-07-31',
            new DateTimeZone('Europe/Moscow'),
        );

        self::assertSame('2026-07-30T21:00:00+00:00', $start->format(DATE_ATOM));
        self::assertSame('2026-07-31T21:00:00+00:00', $end->format(DATE_ATOM));
        self::assertSame(
            '2026-07-31',
            ReportingBalanceDay::resolve($start, new DateTimeZone('Europe/Moscow')),
        );
        self::assertSame(
            '2026-07-31',
            ReportingBalanceDay::resolve(
                $end->modify('-1 microsecond'),
                new DateTimeZone('Europe/Moscow'),
            ),
        );
        self::assertSame(
            '2026-08-01',
            ReportingBalanceDay::resolve($end, new DateTimeZone('Europe/Moscow')),
        );
    }

    public function test_inventory_grain_identity_pins_the_complete_unit_conversion_tuple(): void
    {
        $base = new InventoryRiskGrain(3, 7, 11, 'mass', 'kg', 'conversion-v1');

        self::assertNotSame(
            $base->key(),
            (new InventoryRiskGrain(3, 7, 11, 'mass', 'g', 'conversion-v1'))->key(),
        );
        self::assertNotSame(
            $base->key(),
            (new InventoryRiskGrain(3, 7, 11, 'mass', 'kg', 'conversion-v2'))->key(),
        );
        self::assertNotSame(
            $base->key(),
            (new InventoryRiskGrain(3, 7, 11, 'volume', 'kg', 'conversion-v1'))->key(),
        );
    }
}
