<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Queries;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessRow;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessSnapshot;
use App\Support\Reporting\ImmutableOwnerProjectionReader;

final readonly class LookaheadReadinessRowQuery implements ReportRowQuery
{
    private ImmutableOwnerProjectionReader $reader;

    public function __construct()
    {
        $this->reader = new ImmutableOwnerProjectionReader(
            LookaheadReadinessRow::class,
            LookaheadReadinessSnapshot::class,
            [
                'planned_start_date' => 'planned_start_date',
                'project_id' => 'project_id',
                'task_id' => 'task_id',
                'ready' => 'ready',
                'severity' => 'severity',
                'constraint_id' => 'constraint_id',
                'constraint_type' => 'constraint_type',
                'constraint_status' => 'constraint_status',
                'constraint_age_days' => 'age_days',
                'wbs_code' => 'wbs_code',
            ],
            ['task_state_source_hash'],
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
