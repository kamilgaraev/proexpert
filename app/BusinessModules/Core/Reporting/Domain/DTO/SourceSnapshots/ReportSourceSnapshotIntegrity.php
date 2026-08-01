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

        if ($header->reportQueryIdentity === null
            || $header->reportQueryHash === null
            || ! hash_equals($header->reportQueryHash->value, hash('sha256', CanonicalJson::encode($header->reportQueryIdentity)))) {
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
        return self::hashStream($header, $rows, $drillRows);
    }

    /** @param iterable<ReportSourceSnapshotRow> $rows @param iterable<ReportSourceSnapshotDrillRow> $drillRows */
    public static function hashStream(ReportSourceSnapshotHeader $header, iterable $rows, iterable $drillRows): Sha256Hash
    {
        $context = hash_init('sha256');
        hash_update($context, '{"drill_rows":[');
        self::writeItems($context, $drillRows, static fn (ReportSourceSnapshotDrillRow $row): string => CanonicalJson::encode([
            'column_id' => $row->columnId,
            'ordinal' => $row->ordinal,
            'payload_hash' => $row->payloadHash->value,
            'row_key' => $row->rowKey,
        ]));
        hash_update($context, '],"header":'.CanonicalJson::encode(self::headerPayload($header)).',"rows":[');
        self::writeItems($context, $rows, static fn (ReportSourceSnapshotRow $row): string => CanonicalJson::encode([
            'ordinal' => $row->ordinal,
            'payload_hash' => $row->payloadHash->value,
            'row_key' => $row->rowKey,
        ]));
        hash_update($context, ']}');

        return new Sha256Hash(hash_final($context));
    }

    /** @param iterable<ReportSourceSnapshotRow> $rows @param iterable<ReportSourceSnapshotDrillRow> $drillRows */
    public static function materializedSourceHash(iterable $rows, iterable $drillRows, array $watermarks): Sha256Hash
    {
        $context = hash_init('sha256');
        hash_update($context, '{"drill_rows":[');
        self::writeItems($context, $drillRows, static fn (ReportSourceSnapshotDrillRow $row): string => CanonicalJson::encode($row->payload));
        hash_update($context, '],"rows":[');
        self::writeItems($context, $rows, static fn (ReportSourceSnapshotRow $row): string => CanonicalJson::encode($row->payload));
        hash_update($context, '],"watermarks":'.CanonicalJson::encode($watermarks).'}');

        return new Sha256Hash(hash_final($context));
    }

    private static function headerPayload(ReportSourceSnapshotHeader $header): array
    {
        return [
            'as_of' => $header->asOf->format(DATE_ATOM),
            'organization_id' => $header->scope->organizationId,
            'query_hash' => $header->queryHash->value,
            'report_query_identity' => $header->reportQueryIdentity,
            'report_query_hash' => $header->reportQueryHash?->value,
            'report_code' => $header->reportCode,
            'schema_version' => $header->schemaVersion,
            'scope' => $header->scopeIdentity(),
            'materialized_source_hash' => $header->materializedSourceHash->value,
            'source_kind' => $header->sourceKind,
            'stale_at' => $header->staleAt?->format(DATE_ATOM),
            'watermarks' => $header->watermarks,
        ];
    }

    private static function writeItems(\HashContext $context, iterable $items, callable $encode): void
    {
        $first = true;
        foreach ($items as $item) {
            if (! $first) {
                hash_update($context, ',');
            }
            hash_update($context, $encode($item));
            $first = false;
        }
    }
}
