<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;

interface ProcurementCycleSourceSnapshotWriter
{
    public function persist(ReportQuery $query): ReportSourceSnapshotHeader;
}
