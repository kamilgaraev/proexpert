<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use InvalidArgumentException;

final readonly class ReportSourceSnapshotWrite
{
    public function __construct(
        public ReportSourceSnapshotHeader $header,
        public array $rows,
        public array $drillRows,
    ) {
        if ($header->status !== ReportSourceSnapshotStatus::WRITING
            || count($rows) !== $header->rowCount
            || count($drillRows) !== $header->drillRowCount) {
            throw new InvalidArgumentException('report_source_snapshot_write_invalid');
        }

        $this->assertRows($rows);
        $this->assertDrillRows($drillRows);
    }

    private function assertRows(array $rows): void
    {
        $keys = [];
        foreach ($rows as $position => $row) {
            if (! $row instanceof ReportSourceSnapshotRow
                || $row->snapshotId !== $this->header->id
                || $row->ordinal !== $position + 1
                || isset($keys[$row->rowKey])) {
                throw new InvalidArgumentException('report_source_snapshot_write_invalid');
            }
            $keys[$row->rowKey] = true;
        }
    }

    private function assertDrillRows(array $drillRows): void
    {
        $ordinals = [];
        foreach ($drillRows as $drillRow) {
            if (! $drillRow instanceof ReportSourceSnapshotDrillRow
                || $drillRow->snapshotId !== $this->header->id
                || isset($ordinals[$drillRow->rowKey][$drillRow->columnId][$drillRow->ordinal])) {
                throw new InvalidArgumentException('report_source_snapshot_write_invalid');
            }
            $ordinals[$drillRow->rowKey][$drillRow->columnId][$drillRow->ordinal] = true;
        }
    }
}
