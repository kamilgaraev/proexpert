<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportSourceSnapshotHeader
{
    public function __construct(
        public string $id,
        public string $sourceKind,
        public string $reportCode,
        public string $schemaVersion,
        public ReportScope $scope,
        public Sha256Hash $queryHash,
        public DateTimeImmutable $asOf,
        public Sha256Hash $sourceHash,
        public array $watermarks,
        public DateTimeImmutable $generatedAt,
        public ?DateTimeImmutable $staleAt,
        public ReportSourceSnapshotStatus $status,
        public int $rowCount,
        public int $drillRowCount,
        public Sha256Hash $snapshotHash,
        public ?DateTimeImmutable $readyAt,
        public ?DateTimeImmutable $expiredAt,
    ) {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $id) !== 1
            || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $sourceKind) !== 1
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1
            || trim($schemaVersion) === ''
            || $rowCount < 0
            || $drillRowCount < 0
            || ($staleAt !== null && $staleAt < $generatedAt)
            || ($status === ReportSourceSnapshotStatus::READY && $readyAt === null)
            || ($status !== ReportSourceSnapshotStatus::READY && $readyAt !== null)
            || ($status === ReportSourceSnapshotStatus::EXPIRED && $expiredAt === null)
            || ($status !== ReportSourceSnapshotStatus::EXPIRED && $expiredAt !== null)) {
            throw new InvalidArgumentException('report_source_snapshot_header_invalid');
        }

        CanonicalJson::encode($watermarks);
    }

    public function scopeIdentity(): array
    {
        return $this->scope->canonicalIdentity();
    }
}
