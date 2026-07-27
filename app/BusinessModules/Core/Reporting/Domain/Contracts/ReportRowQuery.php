<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;

interface ReportRowQuery
{
    public function page(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, ?ReportCursor $cursor, int $limit): ReportPage;

    public function cursor(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, int $chunkSize): iterable;
}
