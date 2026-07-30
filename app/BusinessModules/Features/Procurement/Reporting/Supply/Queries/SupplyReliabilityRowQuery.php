<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Queries;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityRow;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilitySnapshot;
use App\Support\Reporting\EloquentOwnerReportRows;

final readonly class SupplyReliabilityRowQuery implements ReportRowQuery
{
    private const SORT_FIELDS = [
        'original_promised_at',
        'supplier_id',
        'purchase_order_id',
        'purchase_order_item_id',
        'delay_bucket',
        'row_key',
    ];

    public function __construct(private EloquentOwnerReportRows $rows) {}

    public function page(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, ?ReportCursor $cursor, int $limit): ReportPage
    {
        return $this->rows->page(
            $context,
            $snapshot,
            SupplyReliabilitySnapshot::class,
            SupplyReliabilityRow::class,
            self::SORT_FIELDS,
            $sort,
            $cursor,
            $limit,
            'project_id',
            'purchase_order_item',
            'purchase_order_item_id',
        );
    }

    public function cursor(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, int $chunkSize): iterable
    {
        return $this->rows->cursor(
            $context,
            $snapshot,
            SupplyReliabilitySnapshot::class,
            SupplyReliabilityRow::class,
            self::SORT_FIELDS,
            $sort,
            $chunkSize,
            'project_id',
            'purchase_order_item',
            'purchase_order_item_id',
        );
    }
}
