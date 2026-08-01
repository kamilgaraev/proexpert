<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use InvalidArgumentException;

final readonly class ReportSourceSnapshotPage
{
    public function __construct(public array $rows, public ?ReportSourceSnapshotCursor $nextCursor)
    {
        foreach ($rows as $row) {
            if (! $row instanceof ReportSourceSnapshotRow) {
                throw new InvalidArgumentException('report_source_snapshot_page_invalid');
            }
        }
    }
}
