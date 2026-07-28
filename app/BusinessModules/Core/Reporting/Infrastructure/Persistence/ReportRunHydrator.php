<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

final class ReportRunHydrator
{
    private const DEFINITION_KEYS = [
        'code', 'definition_hash', 'contract_version', 'formula_version',
        'source_schema_version', 'renderer_version', 'filters', 'columns',
        'sorts', 'formats', 'permission_policy', 'publication_readiness',
        'supports_subscriptions',
    ];

    public function hydrate(ReportRunRecord $record, string $httpDisposition, int $pollAfterMs): ReportRun
    {
        try {
            $query = $this->query($record);
            $status = ReportRunStatus::from($this->string($record->status));
            $this->assertErrorCode($record, $status);
            $activePoll = in_array($status, [ReportRunStatus::QUEUED, ReportRunStatus::MATERIALIZING], true)
                ? $pollAfterMs
                : null;
            $persistedSealed = in_array($status, [ReportRunStatus::READY, ReportRunStatus::EXPIRED], true)
                ? $this->sealed($record, $query->scope)
                : null;
            if ($persistedSealed === null) {
                $this->assertUnsealed($record);
            }
            $sealed = $status === ReportRunStatus::READY ? $persistedSealed : null;

            return new ReportRun(
                $this->string($record->id),
                $this->string($record->report_code),
                $status,
                new Sha256Hash($this->string($record->definition_hash)),
                $this->string($record->contract_version),
                $this->string($record->formula_version),
                $this->string($record->source_schema_version),
                $this->string($record->renderer_version),
                new Sha256Hash($this->string($record->query_hash)),
                $sealed['source_hash'] ?? null,
                $this->integer($record->progress),
                $sealed['row_count'] ?? null,
                $sealed['metadata'] ?? null,
                $sealed['totals'] ?? [],
                $sealed['freshness'] ?? null,
                $sealed['quality'] ?? null,
                $sealed['provenance'] ?? null,
                $this->dateAttribute($record, 'created_at'),
                $this->dateAttribute($record, 'updated_at'),
                $sealed === null ? null : $this->dateAttribute($record, 'ready_at'),
                $this->dateAttribute($record, 'expires_at'),
                $this->raw($record, 'cancel_requested_at') === null ? null : $this->dateAttribute($record, 'cancel_requested_at'),
                $httpDisposition,
                $activePoll,
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, [], $exception);
        }
    }

    public function query(ReportRunRecord $record): ReportQuery
    {
        try {
            $snapshot = $this->closedArray($record->definition_snapshot, self::DEFINITION_KEYS);
            $snapshotCanonical = \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($snapshot);
            if (!hash_equals(hash('sha256', $snapshotCanonical), $this->string($record->definition_snapshot_hash))) {
                throw new \InvalidArgumentException('report_definition_snapshot_digest_mismatch');
            }
            $policy = $this->closedArray($snapshot['permission_policy'], [
                'view_permissions', 'export_permissions', 'sensitive_permissions', 'audit_permissions',
            ]);
            $definition = new ReportDefinition(
                $this->string($snapshot['code']),
                new Sha256Hash($this->string($snapshot['definition_hash'])),
                $this->string($snapshot['contract_version']),
                $this->string($snapshot['formula_version']),
                $this->string($snapshot['source_schema_version']),
                $this->string($snapshot['renderer_version']),
                $this->array($snapshot['filters']),
                $this->array($snapshot['columns']),
                $this->array($snapshot['sorts']),
                $this->array($snapshot['formats']),
                new ReportPermissionPolicy(
                    $this->array($policy['view_permissions']),
                    $this->array($policy['export_permissions']),
                    $this->array($policy['sensitive_permissions']),
                    $this->array($policy['audit_permissions']),
                ),
                ReportPublicationReadiness::from($this->string($snapshot['publication_readiness'])),
                $this->boolean($snapshot['supports_subscriptions']),
            );

            foreach ([
                [$definition->code, $record->report_code],
                [$definition->definitionHash->value, $record->definition_hash],
                [$definition->contractVersion, $record->contract_version],
                [$definition->formulaVersion, $record->formula_version],
                [$definition->sourceSchemaVersion, $record->source_schema_version],
                [$definition->rendererVersion, $record->renderer_version],
            ] as [$actual, $stored]) {
                if (!is_string($stored) || !hash_equals($actual, $stored)) {
                    throw new \InvalidArgumentException('report_definition_snapshot_mismatch');
                }
            }

            $scope = new ReportScope(
                $this->integer($record->organization_id),
                $this->array($record->scope_holding_organization_ids),
                $this->array($record->scope_project_ids),
                $this->array($record->scope_resource_ids),
                new DateTimeZone($this->string($record->scope_timezone)),
            );
            $query = new ReportQuery(
                $definition,
                $scope,
                new ReportFilterSet($this->array($record->filters)),
                $this->array($record->comparison),
                $this->dateAttribute($record, 'as_of'),
                $this->string($record->locale),
            );

            if (!hash_equals($query->queryHash->value, $this->string($record->query_hash))
                || !hash_equals($query->canonicalJson, $this->string($record->canonical_query_json))) {
                throw new \InvalidArgumentException('report_query_identity_mismatch');
            }

            return $query;
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, [], $exception);
        }
    }

    private function sealed(ReportRunRecord $record, ReportScope $scope): array
    {
        $sourceHash = new Sha256Hash($this->string($record->source_hash));
        $snapshot = new ReportSnapshotRef(
            $this->string($record->snapshot_kind),
            $this->string($record->snapshot_id),
            $scope,
            new Sha256Hash($this->string($record->definition_hash)),
            $this->string($record->formula_version),
            $sourceHash,
            $this->dateAttribute($record, 'snapshot_generated_at'),
            $this->raw($record, 'snapshot_stale_at') === null ? null : $this->dateAttribute($record, 'snapshot_stale_at'),
            $this->array($record->snapshot_watermarks),
        );
        $metadataData = $this->closedArray($record->result_metadata, ['row_count', 'generated_at', 'stale_at']);
        $metadataGeneratedAt = $this->date($metadataData['generated_at']);
        $metadataStaleAt = $metadataData['stale_at'] === null ? null : $this->date($metadataData['stale_at']);
        if ($this->string($metadataData['generated_at']) !== $this->utc($metadataGeneratedAt)
            || ($metadataData['stale_at'] !== null && $this->string($metadataData['stale_at']) !== $this->utc($metadataStaleAt))) {
            throw new \InvalidArgumentException('report_result_metadata_instant_invalid');
        }
        $metadata = new ReportResultMetadata(
            $snapshot,
            $this->integer($metadataData['row_count']),
            $metadataGeneratedAt,
            $metadataStaleAt,
        );
        $quality = $this->quality($this->closedArray($record->quality, [
            'status', 'coverage', 'warnings', 'unmatched_count', 'reconciliation', 'unknown_metrics', 'excluded_sources',
        ]));
        $provenance = $this->provenance($this->closedArray($record->provenance, [
            'source_of_truth', 'source_refs', 'source_hash', 'external_confirmation_role',
        ]));
        $freshness = ReportFreshnessStatus::from($this->string($record->freshness));
        $totals = $this->array($record->totals);
        $rowSchema = $this->array($record->row_schema);
        $capabilities = $this->array($record->capabilities);
        $result = new ReportResult($metadata, $totals, $freshness, $quality, $provenance, $rowSchema, $capabilities);

        if ($this->integer($record->row_count) !== $result->metadata->rowCount
            || !hash_equals($sourceHash->value, $result->provenance->sourceHash->value)) {
            throw new \InvalidArgumentException('report_result_identity_mismatch');
        }
        $resultHash = hash('sha256', \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode(
            $this->resultProjection($result),
        ));
        if (!hash_equals($resultHash, $this->string($record->result_hash))) {
            throw new \InvalidArgumentException('report_result_digest_mismatch');
        }

        return compact('sourceHash', 'metadata', 'totals', 'freshness', 'quality', 'provenance')
            + ['source_hash' => $sourceHash, 'row_count' => $result->metadata->rowCount];
    }

    private function resultProjection(ReportResult $result): array
    {
        $snapshot = $result->metadata->snapshot;

        return [
            'metadata' => [
                'snapshot' => [
                    'kind' => $snapshot->kind,
                    'id' => $snapshot->id,
                    'scope' => $snapshot->scope->canonicalIdentity(),
                    'definition_hash' => $snapshot->definitionHash->value,
                    'formula_version' => $snapshot->formulaVersion,
                    'source_hash' => $snapshot->sourceHash->value,
                    'generated_at' => $this->utc($snapshot->generatedAt),
                    'stale_at' => $snapshot->staleAt === null ? null : $this->utc($snapshot->staleAt),
                    'watermarks' => $snapshot->watermarks,
                ],
                'row_count' => $result->metadata->rowCount,
                'generated_at' => $this->utc($result->metadata->generatedAt),
                'stale_at' => $result->metadata->staleAt === null ? null : $this->utc($result->metadata->staleAt),
            ],
            'totals' => $result->totals,
            'freshness' => $result->freshness->value,
            'quality' => $this->qualityProjection($result->quality),
            'provenance' => $this->provenanceProjection($result->provenance),
            'row_schema' => $result->rowSchema,
            'capabilities' => $result->capabilities,
        ];
    }

    private function assertUnsealed(ReportRunRecord $record): void
    {
        foreach ([
            'source_hash', 'result_hash', 'row_count', 'result_metadata', 'freshness',
            'quality', 'provenance', 'row_schema', 'capabilities', 'snapshot_kind',
            'snapshot_id', 'snapshot_generated_at', 'snapshot_stale_at',
            'snapshot_watermarks', 'ready_at',
        ] as $attribute) {
            if ($this->raw($record, $attribute) !== null) {
                throw new \InvalidArgumentException('report_non_ready_identity_invalid');
            }
        }
        if ($this->array($record->totals) !== []) {
            throw new \InvalidArgumentException('report_non_ready_identity_invalid');
        }
    }

    private function assertErrorCode(ReportRunRecord $record, ReportRunStatus $status): void
    {
        $errorCode = $this->raw($record, 'error_code');
        if ($status === ReportRunStatus::FAILED) {
            if (!is_string($errorCode) || ReportErrorCode::tryFrom($errorCode) === null) {
                throw new \InvalidArgumentException('report_error_code_invalid');
            }

            return;
        }
        if ($errorCode !== null) {
            throw new \InvalidArgumentException('report_error_code_invalid');
        }
    }

    private function qualityProjection(ReportQuality $quality): array
    {
        return [
            'status' => $quality->status->value,
            'coverage' => $quality->coverage === null ? null : [
                'numerator' => $quality->coverage->numerator,
                'denominator' => $quality->coverage->denominator,
                'ratio' => $quality->coverage->ratio,
            ],
            'warnings' => array_map(static fn (ReportWarning $warning): array => [
                'code' => $warning->code,
                'severity' => $warning->severity->value,
                'metric' => $warning->metric,
                'affected_row_count' => $warning->affectedRowCount,
            ], $quality->warnings),
            'unmatched_count' => $quality->unmatchedCount,
            'reconciliation' => $quality->reconciliation->value,
            'unknown_metrics' => $quality->unknownMetrics,
            'excluded_sources' => $quality->excludedSources,
        ];
    }

    private function provenanceProjection(ReportProvenance $provenance): array
    {
        return [
            'source_of_truth' => $provenance->sourceOfTruth,
            'source_refs' => array_map(static fn (ReportSourceRef $ref): array => [
                'source' => $ref->source,
                'snapshot_kind' => $ref->snapshotKind,
                'snapshot_id' => $ref->snapshotId,
                'schema_version' => $ref->schemaVersion,
                'watermark' => $ref->watermark,
                'row_count' => $ref->rowCount,
                'hash' => $ref->hash->value,
            ], $provenance->sourceRefs),
            'source_hash' => $provenance->sourceHash->value,
            'external_confirmation_role' => $provenance->externalConfirmationRole,
        ];
    }

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function quality(array $data): ReportQuality
    {
        $coverage = $data['coverage'] === null ? null : $this->closedArray($data['coverage'], ['numerator', 'denominator', 'ratio']);
        $warnings = array_map(function (mixed $warning): ReportWarning {
            $item = $this->closedArray($warning, ['code', 'severity', 'metric', 'affected_row_count']);

            return new ReportWarning(
                $this->string($item['code']),
                ReportWarningSeverity::from($this->string($item['severity'])),
                $item['metric'] === null ? null : $this->string($item['metric']),
                $this->integer($item['affected_row_count']),
            );
        }, $this->array($data['warnings']));

        return new ReportQuality(
            ReportQualityStatus::from($this->string($data['status'])),
            $coverage === null ? null : new ReportCoverage(
                $this->string($coverage['numerator']),
                $this->string($coverage['denominator']),
                $coverage['ratio'] === null ? null : $this->string($coverage['ratio']),
            ),
            $warnings,
            $this->integer($data['unmatched_count']),
            ReportReconciliationStatus::from($this->string($data['reconciliation'])),
            $this->array($data['unknown_metrics']),
            $this->array($data['excluded_sources']),
        );
    }

    private function provenance(array $data): ReportProvenance
    {
        $refs = array_map(function (mixed $ref): ReportSourceRef {
            $item = $this->closedArray($ref, ['source', 'snapshot_kind', 'snapshot_id', 'schema_version', 'watermark', 'row_count', 'hash']);

            return new ReportSourceRef(
                $this->string($item['source']),
                $this->string($item['snapshot_kind']),
                $this->string($item['snapshot_id']),
                $this->string($item['schema_version']),
                $this->string($item['watermark']),
                $this->integer($item['row_count']),
                new Sha256Hash($this->string($item['hash'])),
            );
        }, $this->array($data['source_refs']));

        return new ReportProvenance(
            $this->string($data['source_of_truth']),
            $refs,
            new Sha256Hash($this->string($data['source_hash'])),
            $data['external_confirmation_role'] === null ? null : $this->string($data['external_confirmation_role']),
        );
    }

    private function closedArray(mixed $value, array $keys): array
    {
        $array = $this->array($value);
        $actualKeys = array_keys($array);
        sort($actualKeys, SORT_STRING);
        sort($keys, SORT_STRING);
        if (array_is_list($array) || $actualKeys !== $keys) {
            throw new \InvalidArgumentException('report_persistence_shape_invalid');
        }

        return $array;
    }

    private function array(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('report_persistence_type_invalid');
        }

        return $value;
    }

    private function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('report_persistence_type_invalid');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException('report_persistence_type_invalid');
        }

        return $value;
    }

    private function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('report_persistence_type_invalid');
        }

        return $value;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value)) {
            return new DateTimeImmutable($value);
        }

        throw new \InvalidArgumentException('report_persistence_type_invalid');
    }

    private function dateAttribute(ReportRunRecord $record, string $name): DateTimeImmutable
    {
        return $this->date($this->raw($record, $name));
    }

    private function raw(ReportRunRecord $record, string $name): mixed
    {
        return $record->getAttributes()[$name] ?? null;
    }
}
