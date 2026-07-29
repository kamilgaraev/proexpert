<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityRow;
use App\Support\Reporting\EloquentOwnerDrillDown;

final readonly class SupplyReliabilityDrillDownProvider implements ReportDrillDownProvider
{
    public function __construct(private EloquentOwnerDrillDown $drillDown) {}

    public function drillDown(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportDrillDownRequest $request): ReportDrillDownResult
    {
        return $this->drillDown->resolve(
            $context,
            $snapshot,
            $request,
            SupplyReliabilityRow::class,
            SupplyLifecycleEvent::class,
            'purchase_order_item_id',
            'purchase_order_item_id',
            [
                'event_type',
                'purchase_order_id',
                'purchase_order_item_id',
                'promise_version_id',
                'source_type',
                'source_id',
                'source_version',
                'signed_quantity',
                'unit_dimension',
                'unit_code',
                'conversion_version',
                'occurred_at',
                'reversed_event_id',
                'reason_code',
            ],
            sourceResourceKind: 'purchase_order_item',
            sourceResourceIdColumn: 'purchase_order_item_id',
            requiresSensitive: true,
        );
    }
}
