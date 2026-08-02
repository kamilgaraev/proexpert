<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLineageFilter;
use App\Support\Reporting\CanonicalLineageSummary;
use App\Support\Reporting\LineageCursorPosition;
use App\Support\Reporting\LineageEventPage;

interface AcceptedProductionDrillDownSource
{
    public function findRow(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $token,
    ): ?array;

    public function eventPage(
        ReportExecutionContext $context,
        array $row,
        CanonicalLineageSummary $lineage,
        AcceptedProductionLineageFilter $filter,
        ?LineageCursorPosition $position,
        int $limit,
    ): LineageEventPage;
}
