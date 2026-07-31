<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSourceSnapshotDrillRowRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSourceSnapshotRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSourceSnapshotRowRecord;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EloquentReportSourceSnapshotStore implements ReportSourceSnapshotStore
{
    public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
    {
        ReportSourceSnapshotIntegrity::assertWrite($snapshot);

        return DB::transaction(function () use ($snapshot): ReportSourceSnapshotHeader {
            $header = $snapshot->header;
            ReportSourceSnapshotRecord::query()->create($this->headerAttributes($header));
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
                $header->snapshotHash, $readyAt, null,
            );
        });
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

    private function headerAttributes(ReportSourceSnapshotHeader $header): array
    {
        return [
            'id' => $header->id, 'source_kind' => $header->sourceKind, 'report_code' => $header->reportCode,
            'schema_version' => $header->schemaVersion, 'organization_id' => $header->scope->organizationId,
            'scope_identity' => json_encode($header->scopeIdentity(), JSON_THROW_ON_ERROR), 'query_hash' => $header->queryHash->value,
            'as_of' => $header->asOf, 'source_hash' => $header->sourceHash->value,
            'watermarks' => json_encode($header->watermarks, JSON_THROW_ON_ERROR), 'generated_at' => $header->generatedAt,
            'stale_at' => $header->staleAt, 'status' => $header->status->value, 'row_count' => $header->rowCount,
            'drill_row_count' => $header->drillRowCount, 'snapshot_hash' => $header->snapshotHash->value,
            'ready_at' => null, 'expired_at' => null, 'created_at' => $header->generatedAt, 'updated_at' => $header->generatedAt,
        ];
    }

    private function headerFromRecord(ReportSourceSnapshotRecord $record): ReportSourceSnapshotHeader
    {
        try {
            $identity = $record->scope_identity;
            $resources = array_map(static fn (array $resource): ReportScopedResource => new ReportScopedResource($resource['kind'], $resource['id'], $resource['project_id']), $identity['resources']);
            $scope = new ReportScope($identity['organization_id'], $identity['holding_organization_ids'], $identity['project_ids'], $resources, new DateTimeZone($identity['timezone']));

            return new ReportSourceSnapshotHeader(
                (string) $record->id, (string) $record->source_kind, (string) $record->report_code, (string) $record->schema_version,
                $scope, new Sha256Hash((string) $record->query_hash), $record->as_of, new Sha256Hash((string) $record->source_hash),
                $record->watermarks, $record->generated_at, $record->stale_at, ReportSourceSnapshotStatus::from((string) $record->status),
                (int) $record->row_count, (int) $record->drill_row_count, new Sha256Hash((string) $record->snapshot_hash),
                $record->ready_at, $record->expired_at,
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
