<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportSourceSnapshotStreamDrillRow
{
    public function __construct(
        public string $rowKey,
        public string $columnId,
        public string $sortKey,
        public array $payload,
        public Sha256Hash $payloadHash,
    ) {
        if ($rowKey === '' || $columnId === '' || $sortKey === '') {
            throw new InvalidArgumentException('report_source_snapshot_stream_drill_row_invalid');
        }
    }
}
