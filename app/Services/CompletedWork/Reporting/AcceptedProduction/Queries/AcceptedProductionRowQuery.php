<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Queries;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionRow;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionSnapshot;
use App\Support\Reporting\ImmutableOwnerProjectionReader;

final readonly class AcceptedProductionRowQuery implements ReportRowQuery
{
    private ImmutableOwnerProjectionReader $reader;

    public function __construct()
    {
        $this->reader = new ImmutableOwnerProjectionReader(
            AcceptedProductionRow::class,
            AcceptedProductionSnapshot::class,
            [
                'recognized_on' => 'recognized_on',
                'project_id' => 'project_id',
                'work_id' => 'work_id',
                'performance_act_id' => 'performance_act_id',
                'source_line_id' => 'source_line_id',
                'unit_dimension' => 'unit_dimension',
                'unit_code' => 'unit_code',
                'accepted_quantity' => 'accepted_quantity',
                'accepted_amount_minor' => 'accepted_amount_minor',
            ],
            ['approved_rate_minor', 'accepted_amount_minor'],
        );
    }

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        return $this->reader->page($context, $snapshot, $sort, $cursor, $limit);
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        return $this->reader->cursor($context, $snapshot, $sort, $chunkSize);
    }
}
