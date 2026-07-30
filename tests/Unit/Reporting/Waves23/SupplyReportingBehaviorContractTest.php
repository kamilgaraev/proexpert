<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Backfill\HistoricalInventoryMovementEvidence;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\ReportingBalanceDay;
use App\Support\Reporting\ReportSourceAccessPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SupplyReportingBehaviorContractTest extends TestCase
{
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
}
