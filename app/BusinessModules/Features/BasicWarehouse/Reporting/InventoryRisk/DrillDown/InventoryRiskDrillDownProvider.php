<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryRiskRow;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseInventoryEvent;
use App\Support\Reporting\EloquentOwnerDrillDown;

final readonly class InventoryRiskDrillDownProvider implements ReportDrillDownProvider
{
    public function __construct(private EloquentOwnerDrillDown $drillDown) {}

    public function drillDown(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportDrillDownRequest $request): ReportDrillDownResult
    {
        return $this->drillDown->resolve(
            $context,
            $snapshot,
            $request,
            InventoryRiskRow::class,
            WarehouseInventoryEvent::class,
            'material_id',
            'material_id',
            [
                'warehouse_id',
                'project_id',
                'material_id',
                'source_movement_id',
                'source_version',
                'event_type',
                'on_hand_delta',
                'reserved_delta',
                'transfer_pair_key',
                'unit_dimension',
                'unit_code',
                'conversion_version',
                'occurred_at',
                'source_refs',
            ],
            ['warehouse_id', 'project_id'],
            sourceResourceKind: 'warehouse',
            sourceResourceIdColumn: 'warehouse_id',
        );
    }
}
