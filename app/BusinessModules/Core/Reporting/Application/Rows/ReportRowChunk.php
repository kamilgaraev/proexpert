<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Rows;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportRowChunk
{
    public string $snapshotId;
    public Sha256Hash $queryHash;
    public Sha256Hash $sourceHash;

    /** @param non-empty-list<ReportCursorRow> $rows */
    public function __construct(public array $rows)
    {
        if (! array_is_list($rows) || $rows === [] || count($rows) > 5000) {
            throw new InvalidArgumentException('report_row_chunk_invalid');
        }

        $first = $rows[0] ?? null;
        if (! $first instanceof ReportCursorRow) {
            throw new InvalidArgumentException('report_row_chunk_invalid');
        }

        foreach ($rows as $row) {
            if (! $row instanceof ReportCursorRow
                || ! hash_equals($first->snapshotId, $row->snapshotId)
                || ! hash_equals($first->queryHash->value, $row->queryHash->value)
                || ! hash_equals($first->sourceHash->value, $row->sourceHash->value)) {
                throw new InvalidArgumentException('report_row_chunk_invalid');
            }
        }

        $this->snapshotId = $first->snapshotId;
        $this->queryHash = $first->queryHash;
        $this->sourceHash = $first->sourceHash;
    }

    /** @return list<array> */
    public function values(): array
    {
        return array_map(static fn (ReportCursorRow $row): array => $row->values, $this->rows);
    }
}
