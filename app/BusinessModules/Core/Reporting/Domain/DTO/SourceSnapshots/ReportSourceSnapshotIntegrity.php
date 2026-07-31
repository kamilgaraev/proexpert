<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final class ReportSourceSnapshotIntegrity
{
    public static function assertReadable(ReportSourceSnapshotHeader $header, ReportSourceSnapshotReadRequest $request): void
    {
        if ($header->scope->organizationId !== $request->context->scope->organizationId
            || $header->scopeIdentity() !== $request->context->scope->canonicalIdentity()
            || ! hash_equals($header->queryHash->value, $request->queryHash->value)
            || ! hash_equals($header->sourceKind, $request->sourceKind)
            || ! hash_equals($header->reportCode, $request->reportCode)
            || ! hash_equals($header->schemaVersion, $request->schemaVersion)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        if ($header->status === ReportSourceSnapshotStatus::EXPIRED) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED);
        }

        if ($header->status !== ReportSourceSnapshotStatus::READY) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        if (! $request->allowStale && $header->staleAt !== null && $header->staleAt <= $request->readAt) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED);
        }
    }

    public static function assertWrite(ReportSourceSnapshotWrite $write): void
    {
        $hash = self::hash($write->header, $write->rows, $write->drillRows);
        if (! hash_equals($write->header->snapshotHash->value, $hash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }
    }

    public static function hash(ReportSourceSnapshotHeader $header, array $rows, array $drillRows): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'header' => [
                'as_of' => $header->asOf->format(DATE_ATOM),
                'organization_id' => $header->scope->organizationId,
                'query_hash' => $header->queryHash->value,
                'report_code' => $header->reportCode,
                'schema_version' => $header->schemaVersion,
                'scope' => $header->scopeIdentity(),
                'source_hash' => $header->sourceHash->value,
                'source_kind' => $header->sourceKind,
                'stale_at' => $header->staleAt?->format(DATE_ATOM),
                'watermarks' => $header->watermarks,
            ],
            'rows' => array_map(static fn (ReportSourceSnapshotRow $row): array => [
                'ordinal' => $row->ordinal,
                'payload_hash' => $row->payloadHash->value,
                'row_key' => $row->rowKey,
            ], $rows),
            'drill_rows' => array_map(static fn (ReportSourceSnapshotDrillRow $row): array => [
                'column_id' => $row->columnId,
                'ordinal' => $row->ordinal,
                'payload_hash' => $row->payloadHash->value,
                'row_key' => $row->rowKey,
            ], $drillRows),
        ])));
    }
}
