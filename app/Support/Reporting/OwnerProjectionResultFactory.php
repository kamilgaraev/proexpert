<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class OwnerProjectionResultFactory
{
    public function make(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $snapshotModel,
        string $sourceOfTruth,
        array $sensitiveColumns = [],
    ): ReportResult {
        if (!is_subclass_of($snapshotModel, Model::class)) {
            throw new InvalidArgumentException('owner_projection_snapshot_model_invalid');
        }

        $record = $snapshotModel::query()
            ->whereKey($snapshot->id)
            ->where('organization_id', $context->scope->organizationId)
            ->first();
        if ($record === null || !hash_equals((string) $record->getAttribute('source_hash'), $snapshot->sourceHash->value)) {
            throw new InvalidArgumentException('owner_projection_snapshot_missing');
        }

        $totals = (array) $record->getAttribute('totals');
        $rowSchema = (array) $record->getAttribute('row_schema');
        if (!$context->visibility->canViewSensitive) {
            $totals = $this->redact($totals, $sensitiveColumns);
            $rowSchema = array_values(array_filter(
                $rowSchema,
                static fn (array $item): bool => !in_array($item['id'] ?? null, $sensitiveColumns, true),
            ));
        }

        $sourceRefs = [];
        foreach ((array) $record->getAttribute('source_refs') as $sourceRef) {
            if (!is_array($sourceRef)) {
                continue;
            }
            $sourceRefs[] = new ReportSourceRef(
                source: (string) $sourceRef['source'],
                snapshotKind: (string) $sourceRef['snapshot_kind'],
                snapshotId: (string) $sourceRef['snapshot_id'],
                schemaVersion: (string) $sourceRef['schema_version'],
                watermark: (string) $sourceRef['watermark'],
                rowCount: (int) $sourceRef['row_count'],
                hash: new Sha256Hash((string) $sourceRef['hash']),
            );
        }
        if ($sourceRefs === []) {
            throw new InvalidArgumentException('owner_projection_source_refs_missing');
        }

        $unknownMetrics = array_values(array_filter(
            (array) ($totals['unknown_metrics'] ?? []),
            'is_string',
        ));
        unset($totals['unknown_metrics']);
        $quality = new ReportQuality(
            $unknownMetrics === [] ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            null,
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            $unknownMetrics,
            [],
        );

        return new ReportResult(
            metadata: new ReportResultMetadata(
                $snapshot,
                (int) $record->getAttribute('row_count'),
                $snapshot->generatedAt,
                $snapshot->staleAt,
            ),
            totals: $totals,
            freshness: $snapshot->staleAt !== null && $snapshot->staleAt <= new \DateTimeImmutable()
                ? ReportFreshnessStatus::STALE
                : ReportFreshnessStatus::FRESH,
            quality: $quality,
            provenance: new ReportProvenance(
                $sourceOfTruth,
                $sourceRefs,
                $snapshot->sourceHash,
                null,
            ),
            rowSchema: $rowSchema,
            capabilities: ['rows' => true, 'drill_down' => true, 'export' => true],
        );
    }

    private function redact(array $value, array $sensitiveColumns): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $sensitiveColumns, true)) {
                unset($value[$key]);
                continue;
            }
            if (is_array($item)) {
                $value[$key] = $this->redact($item, $sensitiveColumns);
            }
        }

        return $value;
    }
}
