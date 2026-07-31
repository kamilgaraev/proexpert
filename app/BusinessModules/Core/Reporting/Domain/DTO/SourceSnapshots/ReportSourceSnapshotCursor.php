<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use InvalidArgumentException;

final readonly class ReportSourceSnapshotCursor
{
    public function __construct(public string $snapshotId, public int $afterOrdinal)
    {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $snapshotId) !== 1 || $afterOrdinal < 0) {
            throw new InvalidArgumentException('report_source_snapshot_cursor_invalid');
        }
    }
}
