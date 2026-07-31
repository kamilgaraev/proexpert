<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportSourceSnapshotReadRequest
{
    public function __construct(
        public ReportExecutionContext $context,
        public string $snapshotId,
        public string $sourceKind,
        public string $reportCode,
        public string $schemaVersion,
        public Sha256Hash $queryHash,
        public DateTimeImmutable $readAt,
        public bool $allowStale = false,
    ) {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $snapshotId) !== 1
            || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $sourceKind) !== 1
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1
            || trim($schemaVersion) === '') {
            throw new InvalidArgumentException('report_source_snapshot_read_request_invalid');
        }
    }
}
