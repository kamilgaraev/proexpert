<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\Support\Reporting\CanonicalLineageSummary;
use App\Support\Reporting\LineageCursorPosition;
use App\Support\Reporting\LineageEventPage;

interface LookaheadReadinessDrillDownSource
{
    public function findRow(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $rowKey,
    ): ?array;

    public function eventPage(
        ReportExecutionContext $context,
        array $row,
        CanonicalLineageSummary $lineage,
        string $asOf,
        ?LineageCursorPosition $position,
        int $limit,
    ): LineageEventPage;
}
