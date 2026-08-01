<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use RuntimeException;
use Throwable;

final class ReportSourceSnapshotStreamConflict extends RuntimeException
{
    public function __construct(public readonly ReportSourceSnapshotHeader $candidate, Throwable $previous)
    {
        parent::__construct('report_source_snapshot_stream_conflict', 0, $previous);
    }
}
