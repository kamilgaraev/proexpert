<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Queries;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardDecisionVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardRow;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardSnapshot;
use App\Support\Reporting\EloquentOwnerDrillDown;
use App\Support\Reporting\EloquentOwnerReportRows;

final readonly class SupplierAwardRowQuery implements ReportDrillDownProvider, ReportDrillDownTokenColumns, ReportRowQuery
{
    public function drillDownTokenColumns(): array
    {
        return ['drill' => 'decision_evidence'];
    }

    private const SORT_FIELDS = [
        'selected_at',
        'decision_id',
        'proposal_version_id',
        'supplier_party_id',
        'currency',
        'premium_minor',
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
            SupplierAwardSnapshot::class,
            SupplierAwardRow::class,
            self::SORT_FIELDS,
            $sort,
            $cursor,
            $limit,
            'project_id',
            'supplier_award_decision',
            'decision_id',
        );
    }

    public function cursor(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, int $chunkSize): iterable
    {
        return $this->rows->cursor(
            $context,
            $snapshot,
            SupplierAwardSnapshot::class,
            SupplierAwardRow::class,
            self::SORT_FIELDS,
            $sort,
            $chunkSize,
            'project_id',
            'supplier_award_decision',
            'decision_id',
        );
    }

    public function drillDown(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportDrillDownRequest $request): ReportDrillDownResult
    {
        return $this->drillDown->resolve(
            $context,
            $snapshot,
            $request,
            SupplierAwardRow::class,
            SupplierAwardDecisionVersion::class,
            'decision_id',
            'decision_id',
            [
                'decision_id',
                'decision_version',
                'purchase_request_id',
                'project_id',
                'selected_supplier_party_id',
                'dimension_snapshot',
                'dimension_hash',
                'supplier_request_id',
                'selected_proposal_version_id',
                'cheapest_proposal_version_id',
                'median_proposal_version_id',
                'invited_supplier_ids',
                'comparable_proposal_version_ids',
                'excluded_comparisons',
                'selected_at',
            ],
            ['decision_version'],
            sourceResourceKind: 'supplier_award_decision',
            sourceResourceIdColumn: 'decision_id',
            requiresSensitive: true,
            sourceCutoffColumn: 'selected_at',
        );
    }
}
