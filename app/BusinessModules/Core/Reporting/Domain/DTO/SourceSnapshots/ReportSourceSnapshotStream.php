<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQueryIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use Generator;
use InvalidArgumentException;

final readonly class ReportSourceSnapshotStream
{
    /** @var \Closure(): iterable<ReportSourceSnapshotStreamDrillRow> */
    private \Closure $drillRows;

    /**
     * @param list<ReportSourceSnapshotRow> $rows
     * @param \Closure(): iterable<ReportSourceSnapshotStreamDrillRow> $drillRows
     */
    public function __construct(
        public string $id,
        public string $sourceKind,
        public string $reportCode,
        public string $schemaVersion,
        public ReportScope $scope,
        public Sha256Hash $queryHash,
        public DateTimeImmutable $asOf,
        public array $watermarks,
        public DateTimeImmutable $generatedAt,
        public ?DateTimeImmutable $staleAt,
        public array $rows,
        callable $drillRows,
        public ?ReportQueryIdentity $reportQueryIdentity = null,
    ) {
        $this->drillRows = $drillRows(...);
        foreach ($rows as $ordinal => $row) {
            if (! $row instanceof ReportSourceSnapshotRow || $row->snapshotId !== $id || $row->ordinal !== $ordinal + 1) {
                throw new InvalidArgumentException('report_source_snapshot_stream_invalid');
            }
        }
    }

    /** @return Generator<int, ReportSourceSnapshotStreamDrillRow> */
    public function drillRows(): Generator
    {
        foreach (($this->drillRows)() as $row) {
            if (! $row instanceof ReportSourceSnapshotStreamDrillRow) {
                throw new InvalidArgumentException('report_source_snapshot_stream_invalid');
            }

            yield $row;
        }
    }

    public function header(Sha256Hash $sourceHash, int $drillRowCount, Sha256Hash $snapshotHash): ReportSourceSnapshotHeader
    {
        return new ReportSourceSnapshotHeader(
            $this->id,
            $this->sourceKind,
            $this->reportCode,
            $this->schemaVersion,
            $this->scope,
            $this->queryHash,
            $this->asOf,
            $sourceHash,
            $this->watermarks,
            $this->generatedAt,
            $this->staleAt,
            ReportSourceSnapshotStatus::WRITING,
            count($this->rows),
            $drillRowCount,
            $snapshotHash,
            null,
            null,
            $this->reportQueryIdentity?->projection,
            $this->reportQueryIdentity?->hash,
        );
    }
}
