<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Queries;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCycleRow;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCycleSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use App\Support\Reporting\EloquentOwnerDrillDown;
use App\Support\Reporting\EloquentOwnerReportRows;

final readonly class ProcurementCycleRowQuery implements ReportDrillDownProvider, ReportRowQuery
{
    private const SORT_FIELDS = [
        'cohort_date',
        'purchase_request_line_id',
        'stage',
        'stage_started_at',
        'total_duration_seconds',
        'row_key',
    ];

    public function __construct(
        private EloquentOwnerReportRows $rows,
        private EloquentOwnerDrillDown $drillDown,
    ) {}

    public function page(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, ?ReportCursor $cursor, int $limit): ReportPage
    {
        return $this->rows->page(
            $context,
            $snapshot,
            ProcurementCycleSnapshot::class,
            ProcurementCycleRow::class,
            self::SORT_FIELDS,
            $sort,
            $cursor,
            $limit,
            'project_id',
            'purchase_request_line',
            'purchase_request_line_id',
        );
    }

    public function cursor(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, int $chunkSize): iterable
    {
        return $this->rows->cursor(
            $context,
            $snapshot,
            ProcurementCycleSnapshot::class,
            ProcurementCycleRow::class,
            self::SORT_FIELDS,
            $sort,
            $chunkSize,
            'project_id',
            'purchase_request_line',
            'purchase_request_line_id',
        );
    }

    public function drillDown(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportDrillDownRequest $request): ReportDrillDownResult
    {
        return $this->drillDown->resolve(
            $context,
            $snapshot,
            $request,
            ProcurementCycleRow::class,
            ProcurementProcessEvent::class,
            'purchase_request_line_id',
            'purchase_request_line_id',
            [
                'event_code',
                'stage',
                'occurred_at',
                'purchase_request_id',
                'purchase_request_line_id',
                'supplier_request_id',
                'supplier_proposal_version_id',
                'purchase_order_id',
                'purchase_receipt_id',
            ],
            sourceResourceKind: 'purchase_request_line',
            sourceResourceIdColumn: 'purchase_request_line_id',
            requiresAudit: true,
            rowSourceIdsColumn: 'process_event_ids',
            sourceCutoffColumn: 'occurred_at',
        );
    }
}
