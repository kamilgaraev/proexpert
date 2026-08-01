<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportRunExportSource
{
    public function __construct(
        public ReportRun $run,
        public ReportQuery $query,
        public ReportResult $result,
        public Sha256Hash $resultHash,
        public ReportSnapshotRef $snapshot,
        public ReportDataClassification $dataClassification,
        public ReportOutputClassification $outputClassification,
        public string $contractVersion,
        public string $formulaVersion,
        public string $sourceSchemaVersion,
        public string $rendererVersion,
    ) {
        $resultSnapshot = $result->metadata->snapshot;
        $calculated = new Sha256Hash(hash('sha256', CanonicalJson::encode(self::resultProjection($result))));

        if (
            $run->status !== ReportRunStatus::READY
            || $run->sourceHash === null
            || !hash_equals($run->definitionHash->value, $query->definition->definitionHash->value)
            || !hash_equals($run->queryHash->value, $query->queryHash->value)
            || !hash_equals($run->sourceHash->value, $snapshot->sourceHash->value)
            || !hash_equals($snapshot->definitionHash->value, $run->definitionHash->value)
            || !hash_equals($snapshot->sourceHash->value, $resultSnapshot->sourceHash->value)
            || self::snapshotProjection($snapshot) !== self::snapshotProjection($resultSnapshot)
            || !hash_equals($resultHash->value, $calculated->value)
            || $dataClassification !== $query->definition->outputClassification->defaultClassification
            || self::outputProjection($outputClassification) !== self::outputProjection($query->definition->outputClassification)
            || $contractVersion !== $run->contractVersion
            || $formulaVersion !== $run->formulaVersion
            || $sourceSchemaVersion !== $run->sourceSchemaVersion
            || $rendererVersion !== $run->rendererVersion
            || $formulaVersion !== $snapshot->formulaVersion
        ) {
            throw new InvalidArgumentException('report_run_export_source_invalid');
        }
    }

    private static function resultProjection(ReportResult $result): array
    {
        return [
            'metadata' => [
                'snapshot' => self::snapshotProjection($result->metadata->snapshot),
                'row_count' => $result->metadata->rowCount,
                'generated_at' => self::utc($result->metadata->generatedAt),
                'stale_at' => $result->metadata->staleAt === null ? null : self::utc($result->metadata->staleAt),
            ],
            'totals' => $result->totals,
            'freshness' => $result->freshness->value,
            'quality' => [
                'status' => $result->quality->status->value,
                'coverage' => $result->quality->coverage === null ? null : [
                    'numerator' => $result->quality->coverage->numerator,
                    'denominator' => $result->quality->coverage->denominator,
                    'ratio' => $result->quality->coverage->ratio,
                ],
                'warnings' => array_map(static fn ($warning): array => [
                    'code' => $warning->code,
                    'severity' => $warning->severity->value,
                    'metric' => $warning->metric,
                    'affected_row_count' => $warning->affectedRowCount,
                ], $result->quality->warnings),
                'unmatched_count' => $result->quality->unmatchedCount,
                'reconciliation' => $result->quality->reconciliation->value,
                'unknown_metrics' => $result->quality->unknownMetrics,
                'excluded_sources' => $result->quality->excludedSources,
            ],
            'provenance' => [
                'source_of_truth' => $result->provenance->sourceOfTruth,
                'source_refs' => array_map(static fn ($ref): array => [
                    'source' => $ref->source,
                    'snapshot_kind' => $ref->snapshotKind,
                    'snapshot_id' => $ref->snapshotId,
                    'schema_version' => $ref->schemaVersion,
                    'watermark' => $ref->watermark,
                    'row_count' => $ref->rowCount,
                    'hash' => $ref->hash->value,
                ], $result->provenance->sourceRefs),
                'source_hash' => $result->provenance->sourceHash->value,
                'external_confirmation_role' => $result->provenance->externalConfirmationRole,
            ],
            'row_schema' => $result->rowSchema,
            'capabilities' => $result->capabilities,
        ];
    }

    private static function snapshotProjection(ReportSnapshotRef $snapshot): array
    {
        return [
            'kind' => $snapshot->kind,
            'id' => $snapshot->id,
            'scope' => $snapshot->scope->canonicalIdentity(),
            'definition_hash' => $snapshot->definitionHash->value,
            'formula_version' => $snapshot->formulaVersion,
            'source_hash' => $snapshot->sourceHash->value,
            'generated_at' => self::utc($snapshot->generatedAt),
            'stale_at' => $snapshot->staleAt === null ? null : self::utc($snapshot->staleAt),
            'watermarks' => $snapshot->watermarks,
            'classification' => $snapshot->classification->value,
            'seal' => $snapshot->seal === null ? null : [
                'key_id' => $snapshot->seal->keyId,
                'algorithm' => $snapshot->seal->algorithm,
                'sealed_payload_hash' => $snapshot->seal->sealedPayloadHash->value,
                'signature' => $snapshot->seal->signature,
                'sealed_at' => self::utc($snapshot->seal->sealedAt),
            ],
        ];
    }

    private static function outputProjection(ReportOutputClassification $classification): array
    {
        return [
            $classification->defaultClassification->value,
            $classification->sensitiveColumnIds,
            $classification->auditColumnIds,
            $classification->totalsSensitive,
            $classification->totalsAudit,
            $classification->provenanceAudit,
        ];
    }

    private static function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
