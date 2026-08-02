<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Queries;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlSnapshot;
use App\Support\Reporting\ImmutableOwnerProjectionReader;

final readonly class ProjectEvmControlRowQuery implements ReportRowQuery
{
    private ImmutableOwnerProjectionReader $reader;

    public function __construct()
    {
        $this->reader = new ImmutableOwnerProjectionReader(
            ProjectControlRow::class,
            ProjectControlSnapshot::class,
            [
                'wbs_code' => 'wbs_code',
                'task_id' => 'task_id',
                'currency' => 'currency',
                'bac_minor' => 'bac_minor',
                'pv_minor' => 'pv_minor',
                'ev_minor' => 'ev_minor',
                'ac_minor' => 'ac_minor',
                'sv_minor' => 'sv_minor',
                'cv_minor' => 'cv_minor',
                'spi' => 'spi',
                'cpi' => 'cpi',
                'eac_minor' => 'eac_minor',
            ],
            ['ac_minor', 'approved_etc_minor', 'cv_minor', 'cpi', 'eac_minor', 'vac_minor', 'tcpi'],
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
