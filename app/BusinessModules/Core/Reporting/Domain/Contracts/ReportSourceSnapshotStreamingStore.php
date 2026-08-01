<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotStream;

interface ReportSourceSnapshotStreamingStore extends ReportSourceSnapshotStore
{
    public function resolveReadyStreamed(
        ReportSourceSnapshotIdentity $identity,
        ReportSourceSnapshotStream $snapshot,
    ): ReportSourceSnapshotHeader;
}
