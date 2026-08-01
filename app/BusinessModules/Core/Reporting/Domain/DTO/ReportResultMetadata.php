<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportResultMetadata
{
    public function __construct(
        public ReportSnapshotRef $snapshot,
        public int $rowCount,
        public DateTimeImmutable $generatedAt,
        public ?DateTimeImmutable $staleAt,
    ) {
        if ($rowCount < 0 || $generatedAt != $snapshot->generatedAt || $staleAt != $snapshot->staleAt) {
            throw new InvalidArgumentException('report_result_metadata_invalid');
        }
    }
}
