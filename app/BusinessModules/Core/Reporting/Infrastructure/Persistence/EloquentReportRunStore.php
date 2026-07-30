<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunRetrySource;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotIdentityBuilder;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealValidator;
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
use DateTimeInterface;
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
        private readonly ReportSnapshotSealValidator $sealValidator,
        private readonly ReportSnapshotIdentityBuilder $snapshotIdentities,
        private readonly ReportDispatchIntentStore $dispatchIntents,
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

        return DB::transaction(function () use ($context, $query, $savedView, $idempotencyKey, $fingerprint, $id, $payload, $now): ReportRun {
            $columns = array_keys($payload);
            $bindings = array_values($payload);
            $sql = 'INSERT INTO report_runs ('.implode(', ', $columns).') VALUES ('.
                implode(', ', array_fill(0, count($columns), '?')).
                ') ON CONFLICT (organization_id, idempotency_key_hash) DO NOTHING RETURNING id';
            $inserted = DB::selectOne($sql, $bindings);

            if ($inserted !== null) {
                $this->dispatchIntents->addRunIntent(
                    $id,
                    $context->scope->organizationId,
                    "reports:run:{$id}:materialize:initial",
                    $now,
                );
                $definition = $query->definition;
                $this->audit->append(
                    "reports:run:{$id}:queued",
                    'report.run.queued',
                    $context,
                    [
                        'run_id' => $id,
                        'report_code' => $definition->code,
                        'status' => ReportRunStatus::QUEUED->value,
                        'definition_hash' => $definition->definitionHash->value,
                        'query_hash' => $query->queryHash->value,
                        'contract_version' => $definition->contractVersion,
                        'formula_version' => $definition->formulaVersion,
                        'source_schema_version' => $definition->sourceSchemaVersion,
                        'renderer_version' => $definition->rendererVersion,
                        'saved_view' => $savedView === null ? null : [
                            'id' => $savedView->id,
                            'revision' => $savedView->revision,
                            'hash' => $savedView->hash->value,
                        ],
                    ],
                    $now,
                );

                return $this->hydrator->hydrate($this->locked($context, $id), 'created', $this->pollAfterMs);
            }

            $record = ReportRunRecord::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('idempotency_key_hash', $idempotencyKey->hash)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof ReportRunRecord) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }
            if (! hash_equals((string) $record->input_fingerprint, $fingerprint)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT);
            }

            return $this->hydrator->hydrate($record, 'reused', $this->pollAfterMs);
        });
    }

    public function get(ReportExecutionContext $context, string $runId): ReportRun
    {
        $record = $this->findIncludingExpired($context, $runId);

        return $this->hydrator->hydrate($record, 'reused', $this->pollAfterMs);
    }

    public function queryForRun(ReportExecutionContext $context, string $runId): ReportQuery
    {
        $record = $this->findIncludingExpired($context, $runId);

        return $this->hydrator->query($record);
    }

    public function retrySource(ReportExecutionContext $context, string $runId): ReportRunRetrySource
    {
        return $this->hydrator->retrySource(
            $this->findIncludingExpired($context, $runId),
            $this->pollAfterMs,
        );
    }

    public function exportSource(ReportExecutionContext $context, string $runId): ReportRunExportSource
    {
        $record = $this->find($context, $runId);
        if ($this->isExpiredForExport($record->expires_at, $this->clock->now())) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED);
        }

        return $this->hydrator->exportSource($record, $this->pollAfterMs);
    }

    public function claimMaterialization(ReportExecutionContext $context, string $runId, string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportRun
    {
        $this->assertLeaseInput($leaseToken, $leaseExpiresAt, $occurredAt);

        return DB::transaction(function () use ($context, $runId, $leaseToken, $leaseExpiresAt, $occurredAt): ReportRun {
            $record = $this->locked($context, $runId);
            $this->hydrator->query($record);
            if ($record->status === ReportRunStatus::MATERIALIZING->value) {
                if (
                    ! $this->hasActiveLease($record, $leaseToken, $occurredAt)
                    || ! $this->isMonotonicLeaseRenewal($record, $leaseExpiresAt, $occurredAt)
                ) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
                }
                $this->cas($record, ReportRunStatus::MATERIALIZING, [
                    'execution_lease_expires_at' => $leaseExpiresAt,
                    'execution_heartbeat_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ]);

                return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
            }
            if ($record->status !== ReportRunStatus::QUEUED->value) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            $this->audit->append(
                "reports:run:{$runId}:materializing:{$leaseToken}",
                'report.run.materializing',
                $context,
                [
                    'run_id' => $runId,
                    'report_code' => $record->report_code,
                    'status' => ReportRunStatus::MATERIALIZING->value,
                    'definition_hash' => $record->definition_hash,
                    'query_hash' => $record->query_hash,
                ],
                $occurredAt,
            );
            $this->cas($record, ReportRunStatus::QUEUED, [
                'status' => ReportRunStatus::MATERIALIZING->value,
                'execution_lease_token' => $leaseToken,
                'execution_lease_expires_at' => $leaseExpiresAt,
                'execution_heartbeat_at' => $occurredAt,
                'started_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function persistProgress(ReportExecutionContext $context, string $runId, string $leaseToken, ReportProgress $progress, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportRun
    {
        $this->assertLeaseInput($leaseToken, $leaseExpiresAt, $occurredAt);

        return DB::transaction(function () use ($context, $runId, $leaseToken, $progress, $leaseExpiresAt, $occurredAt): ReportRun {
            $record = $this->locked($context, $runId);
            $this->hydrator->query($record);
            if (! $this->hasActiveLease($record, $leaseToken, $occurredAt) || $progress->percent() < (int) $record->progress || $progress->percent() >= 100) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            $this->cas($record, ReportRunStatus::MATERIALIZING, [
                'progress' => $progress->percent(),
                'execution_lease_expires_at' => $leaseExpiresAt,
                'execution_heartbeat_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function sealReady(ReportExecutionContext $context, string $runId, string $leaseToken, ReportSnapshotRef $snapshot, ReportResult $result, Sha256Hash $sourceHash, DateTimeImmutable $occurredAt): ReportRun
    {
        return DB::transaction(function () use ($context, $runId, $leaseToken, $snapshot, $result, $sourceHash, $occurredAt): ReportRun {
            $record = $this->locked($context, $runId);
            $query = $this->hydrator->query($record);
            $identity = $this->sealedPayload($query, $snapshot, $result, $sourceHash, $occurredAt);
            $this->sealValidator->assertSealable($query, $snapshot, $result, $sourceHash);
            $this->assertSealedInput($record, $query, $snapshot, $result, $sourceHash);

            if ($record->status === ReportRunStatus::READY->value) {
                $this->hydrator->hydrate($record, 'reused', $this->pollAfterMs);
                if (! hash_equals((string) $record->result_hash, (string) $identity['result_hash'])) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
                }

                return $this->hydrator->hydrate($record, 'reused', $this->pollAfterMs);
            }
            if (! $this->hasActiveLease($record, $leaseToken, $occurredAt)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }

            $this->audit->append(
                "reports:run:{$runId}:ready:{$identity['result_hash']}",
                'report.run.ready',
                $context,
                [
                    'run_id' => $runId,
                    'report_code' => $record->report_code,
                    'status' => ReportRunStatus::READY->value,
                    'definition_hash' => $record->definition_hash,
                    'query_hash' => $record->query_hash,
                    'source_hash' => $sourceHash->value,
                    'result_hash' => $identity['result_hash'],
                    'snapshot' => [
                        'kind' => $snapshot->kind,
                        'id' => $snapshot->id,
                        'classification' => $snapshot->classification->value,
                        'seal_digest' => $snapshot->seal === null ? null : hash('sha256', CanonicalJson::encode([
                            'key_id' => $snapshot->seal->keyId,
                            'algorithm' => $snapshot->seal->algorithm,
                            'sealed_payload_hash' => $snapshot->seal->sealedPayloadHash->value,
                            'sealed_at' => $this->utc($snapshot->seal->sealedAt),
                        ])),
                    ],
                    'data_classification' => $record->data_classification,
                    'row_count' => $result->metadata->rowCount,
                    'contract_version' => $record->contract_version,
                    'formula_version' => $record->formula_version,
                    'source_schema_version' => $record->source_schema_version,
                    'renderer_version' => $record->renderer_version,
                ],
                $occurredAt,
            );
            $this->cas($record, ReportRunStatus::MATERIALIZING, $identity + [
                'execution_lease_token' => null,
                'execution_lease_expires_at' => null,
                'execution_heartbeat_at' => null,
            ]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function fail(ReportExecutionContext $context, string $runId, ?string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): ReportRun
    {
        return DB::transaction(function () use ($context, $runId, $leaseToken, $errorCode, $occurredAt): ReportRun {
            $record = $this->locked($context, $runId);
            $this->hydrator->query($record);
            if (! in_array($record->status, [ReportRunStatus::QUEUED->value, ReportRunStatus::MATERIALIZING->value], true)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            if (
                ($record->status === ReportRunStatus::QUEUED->value && $leaseToken !== null)
                || ($record->status === ReportRunStatus::MATERIALIZING->value && ($leaseToken === null || ! $this->hasActiveLease($record, $leaseToken, $occurredAt)))
            ) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            $this->audit->append(
                "reports:run:{$runId}:failed:{$errorCode->value}",
                'report.run.failed',
                $context,
                [
                    'run_id' => $runId,
                    'report_code' => $record->report_code,
                    'status' => ReportRunStatus::FAILED->value,
                    'definition_hash' => $record->definition_hash,
                    'query_hash' => $record->query_hash,
                    'error_code' => $errorCode->value,
                ],
                $occurredAt,
            );
            $this->cas($record, ReportRunStatus::from((string) $record->status), [
                'status' => ReportRunStatus::FAILED->value,
                'error_code' => $errorCode->value,
                'execution_lease_token' => null,
                'execution_lease_expires_at' => null,
                'execution_heartbeat_at' => null,
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
            if (! in_array($record->status, [ReportRunStatus::QUEUED->value, ReportRunStatus::MATERIALIZING->value], true)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            $this->audit->append(
                "reports:run:{$runId}:cancelled",
                'report.run.cancelled',
                $context,
                [
                    'run_id' => $runId,
                    'report_code' => $record->report_code,
                    'status' => ReportRunStatus::CANCELLED->value,
                    'definition_hash' => $record->definition_hash,
                    'query_hash' => $record->query_hash,
                ],
                $occurredAt,
            );
            $this->cas($record, ReportRunStatus::from((string) $record->status), [
                'status' => ReportRunStatus::CANCELLED->value,
                'cancel_requested_at' => $occurredAt,
                'cancelled_at' => $occurredAt,
                'execution_lease_token' => null,
                'execution_lease_expires_at' => null,
                'execution_heartbeat_at' => null,
                'updated_at' => $occurredAt,
            ]);

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
        $record = $this->findIncludingExpired($context, $runId);
        if ($record->status === ReportRunStatus::EXPIRED->value) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED);
        }

        return $record;
    }

    private function findIncludingExpired(ReportExecutionContext $context, string $runId): ReportRunRecord
    {
        $record = $this->scope($context)->whereKey($runId)->first();
        if (! $record instanceof ReportRunRecord) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        return $record;
    }

    private function locked(ReportExecutionContext $context, string $runId): ReportRunRecord
    {
        $record = $this->scope($context)->whereKey($runId)->lockForUpdate()->first();
        if (! $record instanceof ReportRunRecord) {
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
    ): array {
        $definition = $query->definition;

        return [
            'id' => $id,
            'organization_id' => $context->scope->organizationId,
            'requester_actor_id' => $context->actor->id,
            'correlation_lineage_id' => $context->correlationId(),
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
            'scope_resources' => CanonicalJson::encode(array_map(
                static fn (\App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource $resource): array => $resource->canonicalIdentity(),
                $query->scope->resources,
            )),
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

    private function sealedPayload(
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
        ReportResult $result,
        Sha256Hash $sourceHash,
        DateTimeImmutable $occurredAt,
    ): array {
        $snapshotIdentity = $this->snapshotIdentities->build($query, $snapshot, $result);
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
            'snapshot_identity_hash' => $snapshotIdentity->value,
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
            || ! hash_equals($snapshot->definitionHash->value, (string) $record->definition_hash)
            || ! hash_equals($snapshot->sourceHash->value, $sourceHash->value)
            || ! hash_equals($result->provenance->sourceHash->value, $sourceHash->value)
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

    private function assertLeaseInput(string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): void
    {
        if (! Str::isUuid($leaseToken) || $leaseExpiresAt <= $occurredAt) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }
    }

    private function hasActiveLease(ReportRunRecord $record, string $leaseToken, DateTimeImmutable $occurredAt): bool
    {
        $expiresAt = $this->immutableInstant($record->execution_lease_expires_at);

        return $record->status === ReportRunStatus::MATERIALIZING->value
            && is_string($record->execution_lease_token)
            && hash_equals($record->execution_lease_token, $leaseToken)
            && $expiresAt instanceof DateTimeImmutable
            && $expiresAt > $occurredAt;
    }

    private function isMonotonicLeaseRenewal(
        ReportRunRecord $record,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): bool {
        $currentExpiry = $this->immutableInstant($record->execution_lease_expires_at);
        $currentHeartbeat = $this->immutableInstant($record->execution_heartbeat_at);
        $currentUpdatedAt = $this->immutableInstant($record->updated_at);

        return $currentExpiry instanceof DateTimeImmutable
            && $currentHeartbeat instanceof DateTimeImmutable
            && $currentUpdatedAt instanceof DateTimeImmutable
            && $leaseExpiresAt >= $currentExpiry
            && $occurredAt >= $currentHeartbeat
            && $occurredAt >= $currentUpdatedAt;
    }

    private function immutableInstant(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private function isExpiredForExport(mixed $expiresAtValue, DateTimeImmutable $now): bool
    {
        $expiresAt = $this->immutableInstant($expiresAtValue);

        return ! $expiresAt instanceof DateTimeImmutable || $expiresAt <= $now;
    }
}
