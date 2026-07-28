<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunRetrySource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
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
        'sorts', 'formats', 'permission_policy', 'snapshot_classification',
        'output_classification', 'publication_readiness',
        'supports_subscriptions',
    ];

    public function hydrate(ReportRunRecord $record, string $httpDisposition, int $pollAfterMs): ReportRun
    {
        try {
            $query = $this->query($record);
            $status = ReportRunStatus::from($this->string($record->status));
            $this->assertExecutionLease($record, $status);
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
            $outputClassification = $this->closedArray($snapshot['output_classification'], [
                'default_classification', 'sensitive_column_ids', 'audit_column_ids',
                'totals_sensitive', 'totals_audit', 'provenance_audit',
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
                ReportSnapshotClassification::from($this->string($snapshot['snapshot_classification'])),
                new ReportOutputClassification(
                    ReportDataClassification::from($this->string($outputClassification['default_classification'])),
                    $this->array($outputClassification['sensitive_column_ids']),
                    $this->array($outputClassification['audit_column_ids']),
                    $this->boolean($outputClassification['totals_sensitive']),
                    $this->boolean($outputClassification['totals_audit']),
                    $this->boolean($outputClassification['provenance_audit']),
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
                [$definition->snapshotClassification->value, $record->snapshot_classification],
                [$definition->outputClassification->defaultClassification->value, $record->data_classification],
            ] as [$actual, $stored]) {
                if (!is_string($stored) || !hash_equals($actual, $stored)) {
                    throw new \InvalidArgumentException('report_definition_snapshot_mismatch');
                }
            }
            if ($this->array($record->sensitive_column_ids) !== $definition->outputClassification->sensitiveColumnIds
                || $this->array($record->audit_column_ids) !== $definition->outputClassification->auditColumnIds) {
                throw new \InvalidArgumentException('report_definition_classification_mismatch');
            }
            $savedView = $this->savedViewProjection($record);

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
            $fingerprint = hash('sha256', \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode([
                'definition_snapshot_hash' => $this->string($record->definition_snapshot_hash),
                'query' => json_decode($query->canonicalJson, true, 512, JSON_THROW_ON_ERROR),
                'saved_view' => $savedView,
            ]));
            if (!hash_equals($fingerprint, $this->string($record->input_fingerprint))) {
                throw new \InvalidArgumentException('report_input_fingerprint_mismatch');
            }

            return $query;
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, [], $exception);
        }
    }

    public function retrySource(ReportRunRecord $record, int $pollAfterMs): ReportRunRetrySource
    {
        try {
            $run = $this->hydrate($record, 'reused', $pollAfterMs);
            $query = $this->query($record);
            $saved = $this->savedViewProjection($record);
            $savedView = $saved === null ? null : new ReportSavedViewRef(
                $this->string($saved['id']),
                $this->integer($saved['revision']),
                new Sha256Hash($this->string($saved['hash'])),
            );
            $error = $this->raw($record, 'error_code');

            return new ReportRunRetrySource(
                $run,
                $query,
                $savedView,
                $error === null ? null : ReportErrorCode::from($this->string($error)),
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, [], $exception);
        }
    }

    public function exportSource(ReportRunRecord $record, int $pollAfterMs): ReportRunExportSource
    {
        try {
            $run = $this->hydrate($record, 'reused', $pollAfterMs);
            if ($run->status !== ReportRunStatus::READY) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            $query = $this->query($record);
            $sealed = $this->sealed($record, $query->scope);

            return new ReportRunExportSource(
                $run,
                $query,
                $sealed['result'],
                $sealed['result_hash'],
                $sealed['snapshot'],
                ReportDataClassification::from($this->string($record->data_classification)),
                $query->definition->outputClassification,
                $this->string($record->contract_version),
                $this->string($record->formula_version),
                $this->string($record->source_schema_version),
                $this->string($record->renderer_version),
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, [], $exception);
        }
    }

    private function sealed(ReportRunRecord $record, ReportScope $scope): array
    {
        $sourceHash = new Sha256Hash($this->string($record->source_hash));
        $classification = ReportSnapshotClassification::from($this->string($record->snapshot_classification));
        $seal = $this->hydrateSeal($record);
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
            $classification,
            $seal,
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

        return compact('sourceHash', 'metadata', 'totals', 'freshness', 'quality', 'provenance', 'result', 'snapshot')
            + [
                'source_hash' => $sourceHash,
                'row_count' => $result->metadata->rowCount,
                'result_hash' => new Sha256Hash($resultHash),
            ];
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
                    'classification' => $snapshot->classification->value,
                    'seal' => $this->sealProjection($snapshot),
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

    private function hydrateSeal(ReportRunRecord $record): ?ReportSnapshotSeal
    {
        $values = [
            $this->raw($record, 'snapshot_seal_key_id'),
            $this->raw($record, 'snapshot_seal_algorithm'),
            $this->raw($record, 'snapshot_sealed_payload_hash'),
            $this->raw($record, 'snapshot_seal_signature'),
            $this->raw($record, 'snapshot_sealed_at'),
        ];
        $present = array_map(static fn (mixed $value): bool => $value !== null, $values);
        if (!in_array(true, $present, true)) {
            return null;
        }
        if (in_array(false, $present, true)) {
            throw new \InvalidArgumentException('report_snapshot_seal_incomplete');
        }

        return new ReportSnapshotSeal(
            $this->string($record->snapshot_seal_key_id),
            $this->string($record->snapshot_seal_algorithm),
            new Sha256Hash($this->string($record->snapshot_sealed_payload_hash)),
            $this->string($record->snapshot_seal_signature),
            $this->dateAttribute($record, 'snapshot_sealed_at'),
        );
    }

    private function savedViewProjection(ReportRunRecord $record): ?array
    {
        $values = [
            $this->raw($record, 'saved_view_id'),
            $this->raw($record, 'saved_view_revision'),
            $this->raw($record, 'saved_view_hash'),
        ];
        $present = array_map(static fn (mixed $value): bool => $value !== null, $values);
        if (!in_array(true, $present, true)) {
            return null;
        }
        if (in_array(false, $present, true)) {
            throw new \InvalidArgumentException('report_saved_view_reference_incomplete');
        }

        $reference = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef(
            $this->string($record->saved_view_id),
            $this->integer($record->saved_view_revision),
            new Sha256Hash($this->string($record->saved_view_hash)),
        );

        return [
            'id' => $reference->id,
            'revision' => $reference->revision,
            'hash' => $reference->hash->value,
        ];
    }

    private function sealProjection(ReportSnapshotRef $snapshot): ?array
    {
        if ($snapshot->seal === null) {
            return null;
        }

        return [
            'key_id' => $snapshot->seal->keyId,
            'algorithm' => $snapshot->seal->algorithm,
            'sealed_payload_hash' => $snapshot->seal->sealedPayloadHash->value,
            'signature' => $snapshot->seal->signature,
            'sealed_at' => $this->utc($snapshot->seal->sealedAt),
        ];
    }

    private function assertUnsealed(ReportRunRecord $record): void
    {
        foreach ([
            'source_hash', 'result_hash', 'row_count', 'result_metadata', 'freshness',
            'quality', 'provenance', 'row_schema', 'capabilities', 'snapshot_kind',
            'snapshot_id', 'snapshot_generated_at', 'snapshot_stale_at',
            'snapshot_watermarks', 'snapshot_seal_key_id', 'snapshot_seal_algorithm',
            'snapshot_sealed_payload_hash', 'snapshot_seal_signature', 'snapshot_sealed_at',
            'ready_at',
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

    private function assertExecutionLease(ReportRunRecord $record, ReportRunStatus $status): void
    {
        $token = $this->raw($record, 'execution_lease_token');
        $expiresAt = $this->raw($record, 'execution_lease_expires_at');
        $heartbeatAt = $this->raw($record, 'execution_heartbeat_at');
        $present = [$token !== null, $expiresAt !== null, $heartbeatAt !== null];

        if ($status !== ReportRunStatus::MATERIALIZING) {
            if (in_array(true, $present, true)) {
                throw new \InvalidArgumentException('report_run_execution_lease_invalid');
            }

            return;
        }
        if (
            in_array(false, $present, true)
            || !is_string($token)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $token) !== 1
            || $this->date($expiresAt) <= $this->date($heartbeatAt)
        ) {
            throw new \InvalidArgumentException('report_run_execution_lease_invalid');
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
