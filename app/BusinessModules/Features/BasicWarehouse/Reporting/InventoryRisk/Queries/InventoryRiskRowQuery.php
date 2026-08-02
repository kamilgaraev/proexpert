<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Queries;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryRiskRow;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryRiskSnapshot;
use App\Support\Reporting\EloquentOwnerReportRows;

final readonly class InventoryRiskRowQuery implements ReportRowQuery
{
    private const SORT_FIELDS = [
        'balance_date',
        'warehouse_id',
        'material_id',
        'risk_status',
        'available_quantity',
        'row_key',
    ];

    public function __construct(private EloquentOwnerReportRows $rows) {}

    public function page(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, ?ReportCursor $cursor, int $limit): ReportPage
    {
        return $this->rows->page(
            $context,
            $snapshot,
            InventoryRiskSnapshot::class,
            InventoryRiskRow::class,
            self::SORT_FIELDS,
            $sort,
            $cursor,
            $limit,
            'project_id',
            'warehouse',
            'warehouse_id',
        );
    }

    public function cursor(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, int $chunkSize): iterable
    {
        return $this->rows->cursor(
            $context,
            $snapshot,
            InventoryRiskSnapshot::class,
            InventoryRiskRow::class,
            self::SORT_FIELDS,
            $sort,
            $chunkSize,
            'project_id',
            'warehouse',
            'warehouse_id',
        );
    }
}
