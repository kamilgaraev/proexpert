<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;

interface ReportSnapshotIdentityValidator
{
    public function assertMatches(
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
        ReportResult $result,
        mixed $persistedIdentity,
    ): void;
}
