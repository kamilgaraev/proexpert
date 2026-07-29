<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Rows;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use Throwable;

final readonly class ReportRowChunkReader
{
    private const ENVELOPE_FIELDS = [
        'query_hash',
        'row_key',
        'snapshot_id',
        'source_hash',
        'values',
    ];

    /** @return iterable<ReportRowChunk> */
    public function read(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        Sha256Hash $queryHash,
        ReportWindowSort $sort,
        int $chunkSize,
        ReportRowQuery $query,
    ): iterable {
        if ($chunkSize < 1 || $chunkSize > 5000) {
            throw $this->violation();
        }

        $chunk = [];
        foreach ($query->cursor($context, $snapshot, $sort, $chunkSize) as $item) {
            $chunk[] = $this->row($item, $snapshot, $queryHash);
            if (count($chunk) === $chunkSize) {
                yield $this->chunk($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield $this->chunk($chunk);
        }
    }

    private function row(mixed $item, ReportSnapshotRef $snapshot, Sha256Hash $queryHash): ReportCursorRow
    {
        if (! is_array($item) || array_is_list($item)) {
            throw $this->violation();
        }

        $fields = array_keys($item);
        sort($fields, SORT_STRING);
        if ($fields !== self::ENVELOPE_FIELDS
            || ! is_string($item['row_key'])
            || ! is_array($item['values'])
            || ! is_string($item['snapshot_id'])
            || ! is_string($item['query_hash'])
            || ! is_string($item['source_hash'])
            || ! hash_equals($snapshot->id, $item['snapshot_id'])
            || ! hash_equals($queryHash->value, $item['query_hash'])
            || ! hash_equals($snapshot->sourceHash->value, $item['source_hash'])) {
            throw $this->violation();
        }

        try {
            return new ReportCursorRow(
                $item['row_key'],
                $item['values'],
                $item['snapshot_id'],
                new Sha256Hash($item['query_hash']),
                new Sha256Hash($item['source_hash']),
            );
        } catch (Throwable $exception) {
            throw $this->violation($exception);
        }
    }

    /** @param non-empty-list<ReportCursorRow> $rows */
    private function chunk(array $rows): ReportRowChunk
    {
        try {
            return new ReportRowChunk($rows);
        } catch (Throwable $exception) {
            throw $this->violation($exception);
        }
    }

    private function violation(?Throwable $previous = null): ReportContractException
    {
        return ReportContractException::fromCode(
            ReportErrorCode::REPORT_INTERNAL_ERROR,
            previous: $previous,
        );
    }
}
