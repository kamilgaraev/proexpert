<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStreamingStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotStream;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotStreamDrillRow;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSourceSnapshotDrillRowRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSourceSnapshotRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSourceSnapshotRowRecord;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EloquentReportSourceSnapshotStore implements ReportSourceSnapshotStreamingStore
{
    private const READY_IDENTITY_UNIQUE = 'report_source_snapshots_ready_source_identity_unique';

    private const INSERT_CHUNK_SIZE = 250;

    public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
    {
        ReportSourceSnapshotIntegrity::assertWrite($snapshot);

        return $this->persist($snapshot, null);
    }

    public function findReady(ReportSourceSnapshotIdentity $identity): ?ReportSourceSnapshotHeader
    {
        $record = ReportSourceSnapshotRecord::query()
            ->where('source_kind', $identity->sourceKind)
            ->where('report_code', $identity->reportCode)
            ->where('schema_version', $identity->schemaVersion)
            ->where('organization_id', $identity->scope->organizationId)
            ->where('scope_identity_hash', $identity->scopeIdentityHash()->value)
            ->where('query_hash', $identity->queryHash->value)
            ->where('source_version', $identity->sourceVersion)
            ->where('status', ReportSourceSnapshotStatus::READY->value)
            ->first();

        if (! $record instanceof ReportSourceSnapshotRecord) {
            return null;
        }

        $header = $this->headerFromRecord($record);
        if (! $identity->matches($header)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return $header;
    }

    public function resolveReady(
        ReportSourceSnapshotIdentity $identity,
        ReportSourceSnapshotWrite $snapshot,
    ): ReportSourceSnapshotHeader {
        ReportSourceSnapshotIntegrity::assertWrite($snapshot);
        if (! $identity->matches($snapshot->header)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        $ready = $this->findReady($identity);
        if ($ready !== null) {
            return $this->assertCompatible($ready, $snapshot);
        }

        try {
            return $this->persist($snapshot, $identity);
        } catch (QueryException $exception) {
            if (! $this->isReadyIdentityUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = $this->findReady($identity);
            if ($winner === null) {
                throw $exception;
            }

            return $this->assertCompatible($winner, $snapshot, $exception);
        }
    }

    public function resolveReadyStreamed(
        ReportSourceSnapshotIdentity $identity,
        ReportSourceSnapshotStream $snapshot,
    ): ReportSourceSnapshotHeader {
        $ready = $this->findReady($identity);
        if ($ready !== null) {
            return $ready;
        }

        try {
            return $this->persistStreamed($identity, $snapshot);
        } catch (ReportSourceSnapshotStreamConflict $exception) {
            $winner = $this->findReady($identity);
            if ($winner === null) {
                throw $exception->getPrevious() ?? $exception;
            }

            return $this->assertCompatibleHeader($winner, $exception->candidate, $exception);
        } catch (QueryException $exception) {
            if (! $this->isReadyIdentityUniqueViolation($exception)) {
                throw $exception;
            }
            throw $exception;
        }
    }

    private function persist(
        ReportSourceSnapshotWrite $snapshot,
        ?ReportSourceSnapshotIdentity $identity,
    ): ReportSourceSnapshotHeader {
        return DB::transaction(function () use ($snapshot, $identity): ReportSourceSnapshotHeader {
            $header = $snapshot->header;
            ReportSourceSnapshotRecord::query()->create($this->headerAttributes($header, $identity));
            ReportSourceSnapshotRowRecord::query()->insert(array_map(fn (ReportSourceSnapshotRow $row): array => [
                'snapshot_id' => $row->snapshotId, 'ordinal' => $row->ordinal, 'row_key' => $row->rowKey,
                'payload' => json_encode($row->payload, JSON_THROW_ON_ERROR), 'payload_hash' => $row->payloadHash->value,
                'created_at' => $header->generatedAt,
            ], $snapshot->rows));
            ReportSourceSnapshotDrillRowRecord::query()->insert(array_map(fn (ReportSourceSnapshotDrillRow $row): array => [
                'snapshot_id' => $row->snapshotId, 'row_key' => $row->rowKey, 'column_id' => $row->columnId,
                'ordinal' => $row->ordinal, 'payload' => json_encode($row->payload, JSON_THROW_ON_ERROR),
                'payload_hash' => $row->payloadHash->value, 'created_at' => $header->generatedAt,
            ], $snapshot->drillRows));
            $readyAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $affected = ReportSourceSnapshotRecord::query()->whereKey($header->id)
                ->where('status', ReportSourceSnapshotStatus::WRITING->value)
                ->update(['status' => ReportSourceSnapshotStatus::READY->value, 'ready_at' => $readyAt, 'updated_at' => $readyAt]);

            if ($affected !== 1) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }

            return new ReportSourceSnapshotHeader(
                $header->id, $header->sourceKind, $header->reportCode, $header->schemaVersion, $header->scope,
                $header->queryHash, $header->asOf, $header->sourceHash, $header->watermarks, $header->generatedAt,
                $header->staleAt, ReportSourceSnapshotStatus::READY, $header->rowCount, $header->drillRowCount,
                $header->snapshotHash, $readyAt, null, $header->reportQueryIdentity, $header->reportQueryHash,
            );
        });
    }

    private function persistStreamed(
        ReportSourceSnapshotIdentity $identity,
        ReportSourceSnapshotStream $snapshot,
    ): ReportSourceSnapshotHeader {
        return DB::transaction(function () use ($identity, $snapshot): ReportSourceSnapshotHeader {
            $pendingHash = new Sha256Hash(hash('sha256', 'report-source-snapshot-pending:'.$snapshot->id));
            $pending = $snapshot->header($pendingHash, 0, $pendingHash);
            if (! $identity->matches($pending)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }

            ReportSourceSnapshotRecord::query()->create($this->headerAttributes($pending, $identity));
            $this->insertRows($pending, $snapshot->rows);
            $drillRowCount = $this->insertStreamDrillRows($pending, $snapshot);
            $this->normalizeStreamDrillOrdinals($pending->id, $drillRowCount);

            $sourceHash = ReportSourceSnapshotIntegrity::materializedSourceHash(
                $this->streamRows($pending->id),
                $this->streamDrillRows($pending->id),
                $pending->watermarks,
            );
            $writing = $snapshot->header($sourceHash, $drillRowCount, $pendingHash);
            $snapshotHash = ReportSourceSnapshotIntegrity::hashStream(
                $writing,
                $this->streamRows($writing->id),
                $this->streamDrillRows($writing->id),
            );
            $writing = $snapshot->header($sourceHash, $drillRowCount, $snapshotHash);

            ReportSourceSnapshotRecord::query()->whereKey($writing->id)->where('status', ReportSourceSnapshotStatus::WRITING->value)->update([
                'source_hash' => $writing->sourceHash->value,
                'materialized_source_hash' => $writing->materializedSourceHash->value,
                'row_count' => $writing->rowCount,
                'drill_row_count' => $writing->drillRowCount,
                'snapshot_hash' => $writing->snapshotHash->value,
                'updated_at' => $writing->generatedAt,
            ]);

            $readyAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            try {
                $affected = ReportSourceSnapshotRecord::query()->whereKey($writing->id)
                    ->where('status', ReportSourceSnapshotStatus::WRITING->value)
                    ->update(['status' => ReportSourceSnapshotStatus::READY->value, 'ready_at' => $readyAt, 'updated_at' => $readyAt]);
            } catch (QueryException $exception) {
                if ($this->isReadyIdentityUniqueViolation($exception)) {
                    throw new ReportSourceSnapshotStreamConflict($writing, $exception);
                }

                throw $exception;
            }
            if ($affected !== 1) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }

            return new ReportSourceSnapshotHeader(
                $writing->id, $writing->sourceKind, $writing->reportCode, $writing->schemaVersion, $writing->scope,
                $writing->queryHash, $writing->asOf, $writing->sourceHash, $writing->watermarks, $writing->generatedAt,
                $writing->staleAt, ReportSourceSnapshotStatus::READY, $writing->rowCount, $writing->drillRowCount,
                $writing->snapshotHash, $readyAt, null, $writing->reportQueryIdentity, $writing->reportQueryHash,
            );
        });
    }

    /** @param list<ReportSourceSnapshotRow> $rows */
    private function insertRows(ReportSourceSnapshotHeader $header, array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            ReportSourceSnapshotRowRecord::query()->insert(array_map(fn (ReportSourceSnapshotRow $row): array => [
                'snapshot_id' => $row->snapshotId, 'ordinal' => $row->ordinal, 'row_key' => $row->rowKey,
                'payload' => json_encode($row->payload, JSON_THROW_ON_ERROR), 'payload_hash' => $row->payloadHash->value,
                'created_at' => $header->generatedAt,
            ], $chunk));
        }
    }

    private function insertStreamDrillRows(ReportSourceSnapshotHeader $header, ReportSourceSnapshotStream $snapshot): int
    {
        $chunk = [];
        $ordinals = [];
        $count = 0;
        foreach ($snapshot->drillRows() as $row) {
            $ordinal = ($ordinals[$row->rowKey][$row->columnId] ?? 0) + 1;
            $ordinals[$row->rowKey][$row->columnId] = $ordinal;
            $chunk[] = $this->streamDrillAttributes($header, $row, $ordinal);
            $count++;
            if (count($chunk) === self::INSERT_CHUNK_SIZE) {
                ReportSourceSnapshotDrillRowRecord::query()->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            ReportSourceSnapshotDrillRowRecord::query()->insert($chunk);
        }

        return $count;
    }

    private function streamDrillAttributes(
        ReportSourceSnapshotHeader $header,
        ReportSourceSnapshotStreamDrillRow $row,
        int $ordinal,
    ): array {
        return [
            'snapshot_id' => $header->id,
            'row_key' => $row->rowKey,
            'column_id' => $row->columnId,
            'ordinal' => $ordinal,
            'sort_key' => $row->sortKey,
            'payload' => json_encode($row->payload, JSON_THROW_ON_ERROR),
            'payload_hash' => $row->payloadHash->value,
            'created_at' => $header->generatedAt,
        ];
    }

    private function normalizeStreamDrillOrdinals(string $snapshotId, int $drillRowCount): void
    {
        if ($drillRowCount === 0) {
            return;
        }

        ReportSourceSnapshotDrillRowRecord::query()->where('snapshot_id', $snapshotId)->whereNotNull('sort_key')->increment('ordinal', $drillRowCount);
        DB::statement(
            'WITH ranked AS (SELECT snapshot_id, row_key, column_id, sort_key, row_number() OVER '
            . '(PARTITION BY row_key, column_id ORDER BY sort_key) AS target_ordinal FROM report_source_snapshot_drill_rows '
            . 'WHERE snapshot_id = ? AND sort_key IS NOT NULL) '
            . 'UPDATE report_source_snapshot_drill_rows AS target SET ordinal = ranked.target_ordinal FROM ranked '
            . 'WHERE target.snapshot_id = ranked.snapshot_id AND target.row_key = ranked.row_key '
            . 'AND target.column_id = ranked.column_id AND target.sort_key = ranked.sort_key',
            [$snapshotId],
        );
    }

    /** @return \Generator<int, ReportSourceSnapshotRow> */
    private function streamRows(string $snapshotId): \Generator
    {
        foreach (ReportSourceSnapshotRowRecord::query()->where('snapshot_id', $snapshotId)->orderBy('ordinal')->cursor() as $record) {
            yield $this->row($record);
        }
    }

    /** @return \Generator<int, ReportSourceSnapshotDrillRow> */
    private function streamDrillRows(string $snapshotId): \Generator
    {
        foreach (ReportSourceSnapshotDrillRowRecord::query()->where('snapshot_id', $snapshotId)->orderBy('row_key')->orderBy('column_id')->orderBy('ordinal')->cursor() as $record) {
            yield $this->drillRow($record);
        }
    }

    public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
    {
        $header = $this->findHeader($request);
        ReportSourceSnapshotIntegrity::assertReadable($header, $request);

        return $header;
    }

    public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
    {
        $header = $this->header($request);
        $after = $this->after($request->snapshotId, $cursor, $limit);
        $records = ReportSourceSnapshotRowRecord::query()->where('snapshot_id', $header->id)->where('ordinal', '>', $after)
            ->orderBy('ordinal')->limit($limit + 1)->get();
        $rows = $records->map(fn (ReportSourceSnapshotRowRecord $record): ReportSourceSnapshotRow => $this->row($record))->all();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return new ReportSourceSnapshotPage($rows, $hasMore ? new ReportSourceSnapshotCursor($header->id, $rows[array_key_last($rows)]->ordinal) : null);
    }

    public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage
    {
        $header = $this->header($request);
        $after = $this->after($request->snapshotId, $cursor, $limit);
        $records = ReportSourceSnapshotDrillRowRecord::query()->where('snapshot_id', $header->id)->where('row_key', $rowKey)
            ->where('column_id', $columnId)->where('ordinal', '>', $after)->orderBy('ordinal')->limit($limit + 1)->get();
        $rows = $records->map(fn (ReportSourceSnapshotDrillRowRecord $record): ReportSourceSnapshotDrillRow => $this->drillRow($record))->all();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return new ReportSourceSnapshotDrillPage($rows, $hasMore ? new ReportSourceSnapshotCursor($header->id, $rows[array_key_last($rows)]->ordinal) : null);
    }

    private function findHeader(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
    {
        $record = ReportSourceSnapshotRecord::query()->find($request->snapshotId);
        if (! $record instanceof ReportSourceSnapshotRecord) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $this->headerFromRecord($record);
    }

    private function after(string $snapshotId, ?ReportSourceSnapshotCursor $cursor, int $limit): int
    {
        if ($limit < 1 || $limit > 100 || ($cursor !== null && $cursor->snapshotId !== $snapshotId)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
        }

        return $cursor?->afterOrdinal ?? 0;
    }

    private function headerAttributes(
        ReportSourceSnapshotHeader $header,
        ?ReportSourceSnapshotIdentity $identity,
    ): array {
        return [
            'id' => $header->id, 'source_kind' => $header->sourceKind, 'report_code' => $header->reportCode,
            'schema_version' => $header->schemaVersion, 'organization_id' => $header->scope->organizationId,
            'scope_identity' => $header->scopeIdentity(), 'query_hash' => $header->queryHash->value,
            'report_query_identity' => $header->reportQueryIdentity,
            'report_query_hash' => $header->reportQueryHash?->value,
            'scope_identity_hash' => $identity?->scopeIdentityHash()->value,
            'source_version' => $identity?->sourceVersion,
            'as_of' => $header->asOf, 'source_hash' => $header->materializedSourceHash->value,
            'materialized_source_hash' => $header->materializedSourceHash->value,
            'watermarks' => $header->watermarks, 'generated_at' => $header->generatedAt,
            'stale_at' => $header->staleAt, 'status' => $header->status->value, 'row_count' => $header->rowCount,
            'drill_row_count' => $header->drillRowCount, 'snapshot_hash' => $header->snapshotHash->value,
            'ready_at' => null, 'expired_at' => null, 'created_at' => $header->generatedAt, 'updated_at' => $header->generatedAt,
        ];
    }

    private function assertCompatible(
        ReportSourceSnapshotHeader $ready,
        ReportSourceSnapshotWrite $candidate,
        ?Throwable $previous = null,
    ): ReportSourceSnapshotHeader {
        return $this->assertCompatibleHeader($ready, $candidate->header, $previous);
    }

    private function assertCompatibleHeader(
        ReportSourceSnapshotHeader $ready,
        ReportSourceSnapshotHeader $candidate,
        ?Throwable $previous = null,
    ): ReportSourceSnapshotHeader {
        if (! hash_equals($ready->sourceHash->value, $candidate->sourceHash->value)
            || $ready->rowCount !== $candidate->rowCount
            || $ready->drillRowCount !== $candidate->drillRowCount) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT,
                previous: $previous,
            );
        }

        return $ready;
    }

    private function isReadyIdentityUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $details = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return $sqlState === '23505' && str_contains($details, self::READY_IDENTITY_UNIQUE);
    }

    private function headerFromRecord(ReportSourceSnapshotRecord $record): ReportSourceSnapshotHeader
    {
        try {
            $identity = $record->scope_identity;
            $resources = array_map(static fn (array $resource): ReportScopedResource => new ReportScopedResource($resource['kind'], $resource['id'], $resource['project_id']), $identity['resources']);
            $scope = new ReportScope($identity['organization_id'], $identity['holding_organization_ids'], $identity['project_ids'], $resources, new DateTimeZone($identity['timezone']));

            return new ReportSourceSnapshotHeader(
                (string) $record->id, (string) $record->source_kind, (string) $record->report_code, (string) $record->schema_version,
                $scope, new Sha256Hash((string) $record->query_hash), $record->as_of, new Sha256Hash((string) ($record->materialized_source_hash ?? $record->source_hash)),
                $record->watermarks, $record->generated_at, $record->stale_at, ReportSourceSnapshotStatus::from((string) $record->status),
                (int) $record->row_count, (int) $record->drill_row_count, new Sha256Hash((string) $record->snapshot_hash),
                $record->ready_at, $record->expired_at,
                $record->report_query_identity,
                $record->report_query_hash === null ? null : new Sha256Hash((string) $record->report_query_hash),
            );
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, [], $exception);
        }
    }

    private function row(ReportSourceSnapshotRowRecord $record): ReportSourceSnapshotRow
    {
        return new ReportSourceSnapshotRow((string) $record->snapshot_id, (int) $record->ordinal, (string) $record->row_key, $record->payload, new Sha256Hash((string) $record->payload_hash));
    }

    private function drillRow(ReportSourceSnapshotDrillRowRecord $record): ReportSourceSnapshotDrillRow
    {
        return new ReportSourceSnapshotDrillRow((string) $record->snapshot_id, (string) $record->row_key, (string) $record->column_id, (int) $record->ordinal, $record->payload, new Sha256Hash((string) $record->payload_hash));
    }
}
