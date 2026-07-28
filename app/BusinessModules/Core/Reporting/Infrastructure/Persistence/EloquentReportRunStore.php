<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EloquentReportRunStore implements ReportRunStore
{
    public function __construct(
        private readonly ReportExecutionClock $clock,
        private readonly ReportTransitionAudit $audit,
        private readonly ReportRunHydrator $hydrator,
        private readonly int $runTtlSeconds,
        private readonly int $pollAfterMs,
    ) {
        if ($runTtlSeconds < 3600 || $runTtlSeconds > 2592000 || $pollAfterMs < 250 || $pollAfterMs > 30000) {
            throw new InvalidArgumentException('report_run_store_configuration_invalid');
        }
    }

    public function createOrReuse(ReportExecutionContext $context, ReportQuery $query, ?ReportSavedViewRef $savedView, IdempotencyKey $idempotencyKey): ReportRun
    {
        if ($query->scope->organizationId !== $context->scope->organizationId) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        $definitionSnapshot = $this->definitionSnapshot($query);
        $definitionSnapshotCanonical = CanonicalJson::encode($definitionSnapshot);
        $definitionSnapshotHash = hash('sha256', $definitionSnapshotCanonical);
        $fingerprint = hash('sha256', CanonicalJson::encode([
            'definition_snapshot_hash' => $definitionSnapshotHash,
            'query' => json_decode($query->canonicalJson, true, 512, JSON_THROW_ON_ERROR),
            'saved_view' => $savedView === null ? null : [
                'id' => $savedView->id,
                'revision' => $savedView->revision,
                'hash' => $savedView->hash->value,
            ],
        ]));
        $now = $this->clock->now();
        $id = (string) Str::ulid();
        $payload = $this->newRunPayload(
            $id,
            $context,
            $query,
            $savedView,
            $idempotencyKey,
            $fingerprint,
            $definitionSnapshotCanonical,
            $definitionSnapshotHash,
            $now,
        );

        return DB::transaction(function () use ($context, $idempotencyKey, $fingerprint, $id, $payload): ReportRun {
            $columns = array_keys($payload);
            $bindings = array_values($payload);
            $sql = 'INSERT INTO report_runs ('.implode(', ', $columns).') VALUES ('.
                implode(', ', array_fill(0, count($columns), '?')).
                ') ON CONFLICT (organization_id, idempotency_key_hash) DO NOTHING RETURNING id';
            $inserted = DB::selectOne($sql, $bindings);

            if ($inserted !== null) {
                return $this->hydrator->hydrate($this->locked($context, $id), 'created', $this->pollAfterMs);
            }

            $record = ReportRunRecord::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('idempotency_key_hash', $idempotencyKey->hash)
                ->lockForUpdate()
                ->first();
            if (!$record instanceof ReportRunRecord) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }
            if (!hash_equals((string) $record->input_fingerprint, $fingerprint)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT);
            }

            return $this->hydrator->hydrate($record, 'reused', $this->pollAfterMs);
        });
    }

    public function get(ReportExecutionContext $context, string $runId): ReportRun
    {
        $record = $this->find($context, $runId);

        return $this->hydrator->hydrate($record, 'reused', $this->pollAfterMs);
    }

    public function queryForRun(ReportExecutionContext $context, string $runId): ReportQuery
    {
        return $this->hydrator->query($this->find($context, $runId));
    }

    public function startMaterialization(ReportExecutionContext $context, string $runId, DateTimeImmutable $occurredAt): ReportRun
    {
        return $this->transition($context, $runId, ReportRunStatus::QUEUED, ReportRunStatus::MATERIALIZING, [
            'started_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    public function persistProgress(ReportExecutionContext $context, string $runId, ReportProgress $progress, DateTimeImmutable $occurredAt): ReportRun
    {
        return DB::transaction(function () use ($context, $runId, $progress, $occurredAt): ReportRun {
            $record = $this->locked($context, $runId);
            $this->hydrator->query($record);
            if ($record->status !== ReportRunStatus::MATERIALIZING->value || $progress->percent() < (int) $record->progress || $progress->percent() >= 100) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            $this->cas($record, ReportRunStatus::MATERIALIZING, [
                'progress' => $progress->percent(),
                'updated_at' => $occurredAt,
            ]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function sealReady(ReportExecutionContext $context, string $runId, ReportSnapshotRef $snapshot, ReportResult $result, Sha256Hash $sourceHash, DateTimeImmutable $occurredAt): ReportRun
    {
        return DB::transaction(function () use ($context, $runId, $snapshot, $result, $sourceHash, $occurredAt): ReportRun {
            $record = $this->locked($context, $runId);
            $query = $this->hydrator->query($record);
            $identity = $this->sealedPayload($snapshot, $result, $sourceHash, $occurredAt);
            $this->assertSealedInput($record, $query, $snapshot, $result, $sourceHash);

            if ($record->status === ReportRunStatus::READY->value) {
                $this->hydrator->hydrate($record, 'reused', $this->pollAfterMs);
                if (!hash_equals((string) $record->result_hash, (string) $identity['result_hash'])) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
                }

                return $this->hydrator->hydrate($record, 'reused', $this->pollAfterMs);
            }
            if ($record->status !== ReportRunStatus::MATERIALIZING->value) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }

            $this->audit->append(
                "reports:run:{$runId}:ready:{$identity['result_hash']}",
                'report.run.ready',
                $context,
                [
                    'run_id' => $runId,
                    'report_code' => $record->report_code,
                    'definition_hash' => $record->definition_hash,
                    'query_hash' => $record->query_hash,
                    'source_hash' => $sourceHash->value,
                    'result_hash' => $identity['result_hash'],
                    'row_count' => $result->metadata->rowCount,
                ],
                $occurredAt,
            );
            $this->cas($record, ReportRunStatus::MATERIALIZING, $identity);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function fail(ReportExecutionContext $context, string $runId, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): ReportRun
    {
        return DB::transaction(function () use ($context, $runId, $errorCode, $occurredAt): ReportRun {
            $record = $this->locked($context, $runId);
            $this->hydrator->query($record);
            if (!in_array($record->status, [ReportRunStatus::QUEUED->value, ReportRunStatus::MATERIALIZING->value], true)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            $this->cas($record, ReportRunStatus::from((string) $record->status), [
                'status' => ReportRunStatus::FAILED->value,
                'error_code' => $errorCode->value,
                'failed_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function cancel(ReportExecutionContext $context, string $runId, DateTimeImmutable $occurredAt): ReportRun
    {
        return DB::transaction(function () use ($context, $runId, $occurredAt): ReportRun {
            $record = $this->locked($context, $runId);
            $this->hydrator->query($record);
            if (!in_array($record->status, [ReportRunStatus::QUEUED->value, ReportRunStatus::MATERIALIZING->value], true)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            $this->cas($record, ReportRunStatus::from((string) $record->status), [
                'status' => ReportRunStatus::CANCELLED->value,
                'cancel_requested_at' => $occurredAt,
                'cancelled_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    private function transition(ReportExecutionContext $context, string $runId, ReportRunStatus $from, ReportRunStatus $to, array $values): ReportRun
    {
        return DB::transaction(function () use ($context, $runId, $from, $to, $values): ReportRun {
            $record = $this->locked($context, $runId);
            $this->hydrator->query($record);
            if ($record->status !== $from->value) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            $this->cas($record, $from, ['status' => $to->value] + $values);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    private function cas(ReportRunRecord $record, ReportRunStatus $expected, array $values): void
    {
        $updated = $this->statusQualifiedUpdate($record, $expected, $values);
        if ($updated !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }
    }

    private function statusQualifiedUpdate(ReportRunRecord $record, ReportRunStatus $expected, array $values): int
    {
        foreach ($values as $key => $value) {
            if ($value instanceof DateTimeImmutable) {
                $values[$key] = $this->databaseTimestamp($value);
            }
        }

        return ReportRunRecord::query()
            ->whereKey($record->id)
            ->where('organization_id', $record->organization_id)
            ->where('status', $expected->value)
            ->update($values);
    }

    private function find(ReportExecutionContext $context, string $runId): ReportRunRecord
    {
        $record = $this->scope($context)->whereKey($runId)->first();
        if (!$record instanceof ReportRunRecord) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
        if ($record->status === ReportRunStatus::EXPIRED->value) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED);
        }

        return $record;
    }

    private function locked(ReportExecutionContext $context, string $runId): ReportRunRecord
    {
        $record = $this->scope($context)->whereKey($runId)->lockForUpdate()->first();
        if (!$record instanceof ReportRunRecord) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
        if ($record->status === ReportRunStatus::EXPIRED->value) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED);
        }

        return $record;
    }

    private function scope(ReportExecutionContext $context): Builder
    {
        return ReportRunRecord::query()->where('organization_id', $context->scope->organizationId);
    }

    private function newRunPayload(
        string $id,
        ReportExecutionContext $context,
        ReportQuery $query,
        ?ReportSavedViewRef $savedView,
        IdempotencyKey $key,
        string $fingerprint,
        string $definitionSnapshotCanonical,
        string $definitionSnapshotHash,
        DateTimeImmutable $now,
    ): array
    {
        $definition = $query->definition;

        return [
            'id' => $id,
            'organization_id' => $context->scope->organizationId,
            'requester_actor_id' => $context->actor->id,
            'report_code' => $definition->code,
            'status' => ReportRunStatus::QUEUED->value,
            'definition_hash' => $definition->definitionHash->value,
            'definition_snapshot_hash' => $definitionSnapshotHash,
            'query_hash' => $query->queryHash->value,
            'source_hash' => null,
            'idempotency_key_hash' => $key->hash,
            'input_fingerprint' => $fingerprint,
            'contract_version' => $definition->contractVersion,
            'formula_version' => $definition->formulaVersion,
            'source_schema_version' => $definition->sourceSchemaVersion,
            'renderer_version' => $definition->rendererVersion,
            'definition_snapshot' => $definitionSnapshotCanonical,
            'canonical_query_json' => $query->canonicalJson,
            'scope_holding_organization_ids' => CanonicalJson::encode($query->scope->holdingOrganizationIds),
            'scope_project_ids' => CanonicalJson::encode($query->scope->projectIds),
            'scope_resource_ids' => CanonicalJson::encode($query->scope->resourceIds),
            'scope_timezone' => $query->scope->timezone->getName(),
            'filters' => CanonicalJson::encode($query->filters->values),
            'comparison' => CanonicalJson::encode($query->comparison),
            'as_of' => $this->databaseTimestamp($query->asOf),
            'locale' => $query->locale,
            'saved_view_id' => $savedView?->id,
            'saved_view_revision' => $savedView?->revision,
            'saved_view_hash' => $savedView?->hash->value,
            'snapshot_classification' => $definition->snapshotClassification->value,
            'data_classification' => $definition->outputClassification->defaultClassification->value,
            'sensitive_column_ids' => CanonicalJson::encode($definition->outputClassification->sensitiveColumnIds),
            'audit_column_ids' => CanonicalJson::encode($definition->outputClassification->auditColumnIds),
            'progress' => 0,
            'totals' => '[]',
            'queued_at' => $this->databaseTimestamp($now),
            'created_at' => $this->databaseTimestamp($now),
            'updated_at' => $this->databaseTimestamp($now),
            'expires_at' => $this->databaseTimestamp($now->modify("+{$this->runTtlSeconds} seconds")),
        ];
    }

    private function sealedPayload(ReportSnapshotRef $snapshot, ReportResult $result, Sha256Hash $sourceHash, DateTimeImmutable $occurredAt): array
    {
        $quality = $this->qualityPayload($result);
        $provenance = $this->provenancePayload($result);
        $resultSnapshot = $result->metadata->snapshot;
        $resultProjection = [
            'metadata' => [
                'snapshot' => [
                    'kind' => $resultSnapshot->kind,
                    'id' => $resultSnapshot->id,
                    'scope' => $resultSnapshot->scope->canonicalIdentity(),
                    'definition_hash' => $resultSnapshot->definitionHash->value,
                    'formula_version' => $resultSnapshot->formulaVersion,
                    'source_hash' => $resultSnapshot->sourceHash->value,
                    'generated_at' => $this->utc($resultSnapshot->generatedAt),
                    'stale_at' => $resultSnapshot->staleAt === null ? null : $this->utc($resultSnapshot->staleAt),
                    'watermarks' => $resultSnapshot->watermarks,
                    'classification' => $resultSnapshot->classification->value,
                    'seal' => $this->sealProjection($resultSnapshot),
                ],
                'row_count' => $result->metadata->rowCount,
                'generated_at' => $this->utc($result->metadata->generatedAt),
                'stale_at' => $result->metadata->staleAt === null ? null : $this->utc($result->metadata->staleAt),
            ],
            'totals' => $result->totals,
            'freshness' => $result->freshness->value,
            'quality' => $quality,
            'provenance' => $provenance,
            'row_schema' => $result->rowSchema,
            'capabilities' => $result->capabilities,
        ];

        return [
            'status' => ReportRunStatus::READY->value,
            'source_hash' => $sourceHash->value,
            'result_hash' => hash('sha256', CanonicalJson::encode($resultProjection)),
            'progress' => 100,
            'row_count' => $result->metadata->rowCount,
            'result_metadata' => CanonicalJson::encode([
                'row_count' => $result->metadata->rowCount,
                'generated_at' => $this->utc($result->metadata->generatedAt),
                'stale_at' => $result->metadata->staleAt === null ? null : $this->utc($result->metadata->staleAt),
            ]),
            'totals' => CanonicalJson::encode($result->totals),
            'freshness' => $result->freshness->value,
            'quality' => CanonicalJson::encode($quality),
            'provenance' => CanonicalJson::encode($provenance),
            'row_schema' => CanonicalJson::encode($result->rowSchema),
            'capabilities' => CanonicalJson::encode($result->capabilities),
            'snapshot_kind' => $snapshot->kind,
            'snapshot_id' => $snapshot->id,
            'snapshot_generated_at' => $snapshot->generatedAt,
            'snapshot_stale_at' => $snapshot->staleAt,
            'snapshot_watermarks' => CanonicalJson::encode($snapshot->watermarks),
            'snapshot_classification' => $snapshot->classification->value,
            'snapshot_seal_key_id' => $snapshot->seal?->keyId,
            'snapshot_seal_algorithm' => $snapshot->seal?->algorithm,
            'snapshot_sealed_payload_hash' => $snapshot->seal?->sealedPayloadHash->value,
            'snapshot_seal_signature' => $snapshot->seal?->signature,
            'snapshot_sealed_at' => $snapshot->seal?->sealedAt,
            'ready_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ];
    }

    private function definitionSnapshot(ReportQuery $query): array
    {
        $definition = $query->definition;

        return [
            'code' => $definition->code,
            'definition_hash' => $definition->definitionHash->value,
            'contract_version' => $definition->contractVersion,
            'formula_version' => $definition->formulaVersion,
            'source_schema_version' => $definition->sourceSchemaVersion,
            'renderer_version' => $definition->rendererVersion,
            'filters' => $definition->filters,
            'columns' => $definition->columns,
            'sorts' => $definition->sorts,
            'formats' => $definition->formats,
            'permission_policy' => [
                'view_permissions' => $definition->permissionPolicy->viewPermissions,
                'export_permissions' => $definition->permissionPolicy->exportPermissions,
                'sensitive_permissions' => $definition->permissionPolicy->sensitivePermissions,
                'audit_permissions' => $definition->permissionPolicy->auditPermissions,
            ],
            'snapshot_classification' => $definition->snapshotClassification->value,
            'output_classification' => [
                'default_classification' => $definition->outputClassification->defaultClassification->value,
                'sensitive_column_ids' => $definition->outputClassification->sensitiveColumnIds,
                'audit_column_ids' => $definition->outputClassification->auditColumnIds,
                'totals_sensitive' => $definition->outputClassification->totalsSensitive,
                'totals_audit' => $definition->outputClassification->totalsAudit,
                'provenance_audit' => $definition->outputClassification->provenanceAudit,
            ],
            'publication_readiness' => $definition->publicationReadiness->value,
            'supports_subscriptions' => $definition->supportsSubscriptions,
        ];
    }

    private function qualityPayload(ReportResult $result): array
    {
        $quality = $result->quality;

        return [
            'status' => $quality->status->value,
            'coverage' => $quality->coverage === null ? null : [
                'numerator' => $quality->coverage->numerator,
                'denominator' => $quality->coverage->denominator,
                'ratio' => $quality->coverage->ratio,
            ],
            'warnings' => array_map(static fn ($warning): array => [
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

    private function provenancePayload(ReportResult $result): array
    {
        $provenance = $result->provenance;

        return [
            'source_of_truth' => $provenance->sourceOfTruth,
            'source_refs' => array_map(static fn ($ref): array => [
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

    private function assertSealedInput(ReportRunRecord $record, ReportQuery $query, ReportSnapshotRef $snapshot, ReportResult $result, Sha256Hash $sourceHash): void
    {
        if (CanonicalJson::encode($this->snapshotProjection($snapshot))
                !== CanonicalJson::encode($this->snapshotProjection($result->metadata->snapshot))
            || $snapshot->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
            || !hash_equals($snapshot->definitionHash->value, (string) $record->definition_hash)
            || !hash_equals($snapshot->sourceHash->value, $sourceHash->value)
            || !hash_equals($result->provenance->sourceHash->value, $sourceHash->value)
            || $snapshot->formulaVersion !== $record->formula_version
            || $snapshot->classification !== $query->definition->snapshotClassification) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }
    }

    private function snapshotProjection(ReportSnapshotRef $snapshot): array
    {
        return [
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

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function databaseTimestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }
}
