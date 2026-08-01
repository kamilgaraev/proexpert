<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportReadyDownloadStore;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Services\Storage\DTO\StoredFile;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EloquentReportExportStore implements ReportExportStore, ReportReadyDownloadStore
{
    public function __construct(
        private readonly ReportExecutionClock $clock,
        private readonly ReportTransitionAudit $audit,
        private readonly ReportExportHydrator $hydrator,
        private readonly ReportDispatchIntentStore $dispatchIntents,
        private readonly int $exportTtlSeconds,
        private readonly int $pollAfterMs,
        private readonly ReportExecutionRuntimeConfiguration $runtime,
    ) {
        if ($exportTtlSeconds < 3600 || $exportTtlSeconds > 2592000 || $pollAfterMs < 250 || $pollAfterMs > 30000) {
            throw new InvalidArgumentException('report_export_store_configuration_invalid');
        }
    }

    public function createOrReuse(
        ReportExecutionContext $context,
        ReportRunExportSource $source,
        CreateReportExportData $data,
        IdempotencyKey $idempotencyKey,
        ReportAuthorizationFence $fence,
    ): ReportExport {
        if ($source->query->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
        if ($fence->exportFormat === null || ! hash_equals($data->format, $fence->exportFormat)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        $columns = $source->query->definition->validatedSelectedColumnIds($data->columns);
        $id = (string) Str::ulid();
        $identity = $this->identity($source, $data, $columns);

        return $this->serializableTransaction(function () use ($context, $source, $data, $idempotencyKey, $identity, $columns, $id, $fence): ReportExport {
            $parentRun = ReportRunRecord::query()
                ->whereKey($source->run->id)
                ->where('organization_id', $context->scope->organizationId)
                ->lockForUpdate()
                ->first();
            if (! $parentRun instanceof ReportRunRecord) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
            $fence->assertOperations(
                $this->requiredOperations($parentRun, $columns, ReportOperation::EXPORT),
            );
            $this->assertRunFence($context, $source, $parentRun, $fence);
            $currentContext = $fence->assertCurrent($context);
            $now = $this->clock->now();
            if ($this->expired($parentRun->expires_at, $now) || $parentRun->status === 'expired') {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED);
            }
            if ($parentRun->status !== 'ready') {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            if ($parentRun->correlation_lineage_id !== null && ! is_string($parentRun->correlation_lineage_id)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }
            $payload = $this->payload(
                $id,
                $currentContext,
                $source,
                $data,
                $columns,
                $idempotencyKey,
                $identity,
                $parentRun->correlation_lineage_id,
                $now,
            );
            $columns = array_keys($payload);
            $inserted = DB::selectOne(
                'INSERT INTO report_exports ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).') ON CONFLICT (organization_id, idempotency_key_hash) DO NOTHING RETURNING id',
                array_values($payload),
            );
            if ($inserted === null) {
                $record = ReportExportRecord::query()
                    ->where('organization_id', $currentContext->scope->organizationId)
                    ->where('idempotency_key_hash', $idempotencyKey->hash)
                    ->lockForUpdate()
                    ->first();
                if (! $record instanceof ReportExportRecord) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
                }
                $this->assertExactRecordScope($currentContext, $record);
                if (! hash_equals((string) $record->input_fingerprint, $identity['input_fingerprint'])) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT);
                }

                return $this->hydrator->hydrate($record, 'reused', $this->pollAfterMs);
            }

            $this->dispatchIntents->addExportIntent($id, $currentContext->scope->organizationId, "reports:export:{$id}:generate:initial", $now);
            $this->audit->append(
                "reports:export:{$id}:queued",
                'report.export.queued',
                $currentContext,
                [
                    'export_id' => $id,
                    'run_id' => $source->run->id,
                    'report_code' => $source->query->definition->code,
                    'status' => ReportExportStatus::QUEUED->value,
                    'definition_hash' => $source->query->definition->definitionHash->value,
                    'query_hash' => $source->query->queryHash->value,
                    'source_hash' => $source->snapshot->sourceHash->value,
                    'result_hash' => $source->resultHash->value,
                    'snapshot_id' => $source->snapshot->id,
                    'snapshot_classification' => $source->snapshot->classification->value,
                    'data_classification' => $source->dataClassification->value,
                    'format' => $data->format,
                    'columns' => $payload['selected_columns'],
                    'locale' => $data->locale,
                    'timezone' => $data->timezone->getName(),
                    'renderer_version' => $source->rendererVersion,
                ],
                $now,
            );

            return $this->hydrator->hydrate($this->locked($currentContext, $id), 'created', $this->pollAfterMs);
        });
    }

    public function get(ReportExecutionContext $context, string $exportId): ReportExport
    {
        return $this->hydrator->hydrate($this->find($context, $exportId), 'reused', $this->pollAfterMs);
    }

    public function startRendering(ReportExecutionContext $context, string $exportId, string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportExport
    {
        $this->assertLeaseInput($leaseToken, $leaseExpiresAt, $occurredAt);

        return DB::transaction(function () use ($context, $exportId, $leaseToken, $leaseExpiresAt, $occurredAt): ReportExport {
            $record = $this->locked($context, $exportId);
            if ($record->status === ReportExportStatus::RUNNING->value && $this->hasLiveLease($record, $leaseToken, $occurredAt)) {
                $this->cas($record, ReportExportStatus::RUNNING, ['execution_lease_expires_at' => $leaseExpiresAt, 'execution_heartbeat_at' => $occurredAt, 'updated_at' => $occurredAt]);

                return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
            }
            if ($record->status !== ReportExportStatus::QUEUED->value) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_NOT_READY);
            }
            $this->audit->append("reports:export:{$exportId}:running:{$leaseToken}", 'report.export.running', $context, $this->transitionSubject($record, ReportExportStatus::RUNNING), $occurredAt);
            $this->cas($record, ReportExportStatus::QUEUED, [
                'status' => ReportExportStatus::RUNNING->value,
                'execution_lease_token' => $leaseToken,
                'execution_lease_expires_at' => $leaseExpiresAt,
                'execution_heartbeat_at' => $occurredAt,
                'started_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function startUploading(ReportExecutionContext $context, string $exportId, string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportExport
    {
        $this->assertLeaseInput($leaseToken, $leaseExpiresAt, $occurredAt);

        return DB::transaction(function () use ($context, $exportId, $leaseToken, $leaseExpiresAt, $occurredAt): ReportExport {
            $record = $this->locked($context, $exportId);
            if (! $this->hasLiveLease($record, $leaseToken, $occurredAt) || $record->status !== ReportExportStatus::RUNNING->value) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_NOT_READY);
            }
            $this->audit->append("reports:export:{$exportId}:uploading:{$leaseToken}", 'report.export.uploading', $context, $this->transitionSubject($record, ReportExportStatus::UPLOADING), $occurredAt);
            $this->cas($record, ReportExportStatus::RUNNING, [
                'status' => ReportExportStatus::UPLOADING->value,
                'execution_lease_expires_at' => $leaseExpiresAt,
                'execution_heartbeat_at' => $occurredAt,
                'uploading_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function sealReady(ReportExecutionContext $context, string $exportId, string $leaseToken, StoredFile $artifact, int $rowCount, DateTimeImmutable $occurredAt): ReportExport
    {
        if ($rowCount < 0) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return DB::transaction(function () use ($context, $exportId, $leaseToken, $artifact, $rowCount, $occurredAt): ReportExport {
            $record = $this->locked($context, $exportId);
            if ($record->status !== ReportExportStatus::UPLOADING->value || ! $this->hasLiveLease($record, $leaseToken, $occurredAt)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_NOT_READY);
            }
            $this->assertParentIdentity($record);
            $subject = $this->transitionSubject($record, ReportExportStatus::READY) + ['definition_hash' => (string) $record->definition_hash, 'query_hash' => (string) $record->query_hash, 'source_hash' => (string) $record->source_hash, 'result_hash' => (string) $record->result_hash, 'snapshot_id' => (string) $record->snapshot_id, 'renderer_version' => (string) $record->renderer_version, 'row_count' => $rowCount, 'artifact' => ['version_id' => $artifact->versionId, 'etag' => $artifact->etag, 'checksum' => $artifact->checksum->value, 'size' => $artifact->sizeBytes, 'mime' => $artifact->mime]];
            $this->audit->append("reports:export:{$exportId}:ready:{$artifact->checksum->value}", 'report.export.ready', $context, $subject, $occurredAt);
            $this->cas($record, ReportExportStatus::UPLOADING, [
                'status' => ReportExportStatus::READY->value,
                'artifact_path' => $artifact->path,
                'artifact_version_id' => $artifact->versionId,
                'artifact_etag' => $artifact->etag,
                'artifact_mime' => $artifact->mime,
                'artifact_checksum' => $artifact->checksum->value,
                'artifact_size_bytes' => $artifact->sizeBytes,
                'row_count' => $rowCount,
                'ready_at' => $occurredAt,
                'execution_lease_token' => null,
                'execution_lease_expires_at' => null,
                'execution_heartbeat_at' => null,
                'updated_at' => $occurredAt,
            ]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function fail(ReportExecutionContext $context, string $exportId, ?string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): ReportExport
    {
        if ($this->isRetryable($errorCode)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_NOT_READY);
        }

        return DB::transaction(function () use ($context, $exportId, $leaseToken, $errorCode, $occurredAt): ReportExport {
            $record = $this->locked($context, $exportId);
            $status = ReportExportStatus::from((string) $record->status);
            if (! in_array($status, [ReportExportStatus::QUEUED, ReportExportStatus::RUNNING, ReportExportStatus::UPLOADING], true) || ($status === ReportExportStatus::QUEUED ? $leaseToken !== null : ($leaseToken === null || ! $this->hasLiveLease($record, $leaseToken, $occurredAt)))) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_NOT_READY);
            }
            $this->audit->append("reports:export:{$exportId}:failed:{$errorCode->value}", 'report.export.failed', $context, $this->transitionSubject($record, ReportExportStatus::FAILED) + ['error_code' => $errorCode->value], $occurredAt);
            $this->cas($record, $status, ['status' => ReportExportStatus::FAILED->value, 'error_code' => $errorCode->value, 'execution_lease_token' => null, 'execution_lease_expires_at' => null, 'execution_heartbeat_at' => null, 'failed_at' => $occurredAt, 'updated_at' => $occurredAt]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function cancel(
        ReportExecutionContext $context,
        string $exportId,
        DateTimeImmutable $occurredAt,
        ReportAuthorizationFence $fence,
    ): ReportExport {
        return $this->serializableTransaction(function () use ($context, $exportId, $occurredAt, $fence): ReportExport {
            $record = $this->locked($context, $exportId);
            $fence->assertOperations(
                $this->requiredOperations(
                    $record,
                    $this->selectedColumns($record),
                    ReportOperation::EXPORT,
                ),
            );
            $this->assertExportFence($context, $record, $fence);
            $currentContext = $fence->assertCurrent($context);
            $status = ReportExportStatus::from((string) $record->status);
            if (! in_array($status, [ReportExportStatus::QUEUED, ReportExportStatus::RUNNING, ReportExportStatus::UPLOADING], true)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_NOT_READY);
            }
            $this->audit->append("reports:export:{$exportId}:cancelled", 'report.export.cancelled', $currentContext, $this->transitionSubject($record, ReportExportStatus::CANCELLED), $occurredAt);
            $this->cas($record, $status, ['status' => ReportExportStatus::CANCELLED->value, 'cancel_requested_at' => $occurredAt, 'cancelled_at' => $occurredAt, 'execution_lease_token' => null, 'execution_lease_expires_at' => null, 'execution_heartbeat_at' => null, 'updated_at' => $occurredAt]);

            return $this->hydrator->hydrate($record->fresh(), 'reused', $this->pollAfterMs);
        });
    }

    public function withReadyDownload(
        ReportExecutionContext $context,
        string $exportId,
        int $requestedTtlSeconds,
        ReportAuthorizationFence $fence,
        Closure $presign,
    ): ReportDownloadLink {
        if ($requestedTtlSeconds < 1 || $requestedTtlSeconds > 300) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return $this->serializableTransaction(function () use ($context, $exportId, $requestedTtlSeconds, $fence, $presign): ReportDownloadLink {
            $record = $this->locked($context, $exportId);
            $fence->assertOperations(
                $this->requiredOperations(
                    $record,
                    $this->selectedColumns($record),
                    ReportOperation::DOWNLOAD,
                ),
            );
            $this->assertExportFence($context, $record, $fence);

            $parent = ReportRunRecord::query()
                ->whereKey($record->run_id)
                ->where('organization_id', $record->organization_id)
                ->lockForUpdate()
                ->first();
            if (! $parent instanceof ReportRunRecord) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
            $fence->assertCurrent($context);
            $occurredAt = $this->clock->now();
            if ($this->expired($record->expires_at, $occurredAt) || $record->status === ReportExportStatus::EXPIRED->value) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_EXPIRED);
            }
            if ($record->status !== ReportExportStatus::READY->value) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_NOT_READY);
            }
            if ($this->expired($parent->expires_at, $occurredAt) || $parent->status === 'expired') {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED);
            }
            if ($parent->status !== 'ready') {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
            }
            $this->assertParentIdentity($record, $parent);

            $ttlSeconds = min(
                $requestedTtlSeconds,
                $this->remainingSeconds($record->expires_at, $occurredAt),
                $this->remainingSeconds($parent->expires_at, $occurredAt),
            );
            if ($ttlSeconds < 1) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_EXPIRED);
            }

            return $presign(
                $this->hydrator->hydrate($record, 'reused', $this->pollAfterMs),
                $ttlSeconds,
            );
        });
    }

    private function identity(ReportRunExportSource $source, CreateReportExportData $data, array $columns): array
    {
        $snapshot = $source->snapshot;
        $projection = [
            'run_id' => $source->run->id,
            'scope' => $source->query->scope->canonicalIdentity(),
            'result_hash' => $source->resultHash->value,
            'snapshot' => ['kind' => $snapshot->kind, 'id' => $snapshot->id, 'classification' => $snapshot->classification->value, 'seal' => $snapshot->seal === null ? null : ['key_id' => $snapshot->seal->keyId, 'algorithm' => $snapshot->seal->algorithm, 'sealed_payload_hash' => $snapshot->seal->sealedPayloadHash->value, 'signature' => $snapshot->seal->signature, 'sealed_at' => $this->utc($snapshot->seal->sealedAt)]],
            'definition_hash' => $source->query->definition->definitionHash->value,
            'query_hash' => $source->query->queryHash->value,
            'source_hash' => $snapshot->sourceHash->value,
            'data_classification' => $source->dataClassification->value,
            'output_classification' => ['sensitive_column_ids' => $source->outputClassification->sensitiveColumnIds, 'audit_column_ids' => $source->outputClassification->auditColumnIds, 'totals_sensitive' => $source->outputClassification->totalsSensitive, 'totals_audit' => $source->outputClassification->totalsAudit, 'provenance_audit' => $source->outputClassification->provenanceAudit],
            'versions' => ['contract' => $source->contractVersion, 'formula' => $source->formulaVersion, 'source_schema' => $source->sourceSchemaVersion, 'renderer' => $source->rendererVersion],
            'format' => $data->format,
            'columns' => $columns,
            'sort' => ['field' => $data->sort->field, 'direction' => $data->sort->direction->value],
            'locale' => $data->locale,
            'timezone' => $data->timezone->getName(),
        ];
        $canonical = CanonicalJson::encode($projection);

        return ['export_hash' => hash('sha256', $canonical), 'input_fingerprint' => hash('sha256', $canonical)];
    }

    private function payload(string $id, ReportExecutionContext $context, ReportRunExportSource $source, CreateReportExportData $data, array $columns, IdempotencyKey $idempotencyKey, array $identity, ?string $correlationLineageId, DateTimeImmutable $now): array
    {
        $scope = $source->query->scope;
        $snapshot = $source->snapshot;
        $seal = $snapshot->seal;

        return [
            'id' => $id, 'run_id' => $source->run->id, 'organization_id' => $context->scope->organizationId, 'requester_actor_id' => $context->actor->id, 'correlation_lineage_id' => $correlationLineageId, 'report_code' => $source->query->definition->code, 'status' => ReportExportStatus::QUEUED->value,
            'definition_hash' => $source->query->definition->definitionHash->value, 'query_hash' => $source->query->queryHash->value, 'source_hash' => $snapshot->sourceHash->value, 'result_hash' => $source->resultHash->value, 'export_hash' => $identity['export_hash'], 'idempotency_key_hash' => $idempotencyKey->hash, 'input_fingerprint' => $identity['input_fingerprint'],
            'scope_holding_organization_ids' => json_encode($scope->holdingOrganizationIds, JSON_THROW_ON_ERROR), 'scope_project_ids' => json_encode($scope->projectIds, JSON_THROW_ON_ERROR), 'scope_resources' => json_encode(array_map(static fn ($resource): array => $resource->canonicalIdentity(), $scope->resources), JSON_THROW_ON_ERROR), 'scope_timezone' => $scope->timezone->getName(),
            'snapshot_kind' => $snapshot->kind, 'snapshot_id' => $snapshot->id, 'snapshot_generated_at' => $this->timestamp($snapshot->generatedAt), 'snapshot_stale_at' => $snapshot->staleAt === null ? null : $this->timestamp($snapshot->staleAt), 'snapshot_watermarks' => json_encode($snapshot->watermarks, JSON_THROW_ON_ERROR), 'snapshot_classification' => $snapshot->classification->value,
            'snapshot_seal_key_id' => $seal?->keyId, 'snapshot_seal_algorithm' => $seal?->algorithm, 'snapshot_sealed_payload_hash' => $seal?->sealedPayloadHash->value, 'snapshot_seal_signature' => $seal?->signature, 'snapshot_sealed_at' => $seal === null ? null : $this->timestamp($seal->sealedAt),
            'data_classification' => $source->dataClassification->value, 'sensitive_column_ids' => json_encode($source->outputClassification->sensitiveColumnIds, JSON_THROW_ON_ERROR), 'audit_column_ids' => json_encode($source->outputClassification->auditColumnIds, JSON_THROW_ON_ERROR), 'totals_sensitive' => $source->outputClassification->totalsSensitive, 'totals_audit' => $source->outputClassification->totalsAudit, 'provenance_audit' => $source->outputClassification->provenanceAudit,
            'contract_version' => $source->contractVersion, 'formula_version' => $source->formulaVersion, 'source_schema_version' => $source->sourceSchemaVersion, 'renderer_version' => $source->rendererVersion, 'format' => $data->format, 'selected_columns' => json_encode($columns, JSON_THROW_ON_ERROR), 'sort_field' => $data->sort->field, 'sort_direction' => $data->sort->direction->value, 'locale' => $data->locale, 'render_timezone' => $data->timezone->getName(),
            'queued_at' => $this->timestamp($now), 'created_at' => $this->timestamp($now), 'updated_at' => $this->timestamp($now), 'expires_at' => $this->timestamp($now->modify("+{$this->exportTtlSeconds} seconds")),
        ];
    }

    private function transitionSubject(ReportExportRecord $record, ReportExportStatus $status): array
    {
        return ['export_id' => (string) $record->id, 'run_id' => (string) $record->run_id, 'report_code' => (string) $record->report_code, 'status' => $status->value, 'format' => (string) $record->format];
    }

    private function assertParentIdentity(
        ReportExportRecord $record,
        ?ReportRunRecord $run = null,
    ): void {
        $run ??= ReportRunRecord::query()
            ->whereKey($record->run_id)
            ->where('organization_id', $record->organization_id)
            ->lockForUpdate()
            ->first();
        if (! $run instanceof ReportRunRecord || $run->status !== 'ready') {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }
        foreach ($this->parentIdentityPairs($record, $run) as [$exportValue, $runValue]) {
            if (! $this->identityValuesMatch($exportValue, $runValue)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }
        }
    }

    private function parentIdentityPairs(ReportExportRecord $record, ReportRunRecord $run): array
    {
        $definitionSnapshot = $run->definition_snapshot;
        $outputClassification = is_array($definitionSnapshot)
            ? ($definitionSnapshot['output_classification'] ?? null)
            : null;
        if (! is_array($outputClassification)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return [
            [$record->report_code, $run->report_code], [$record->definition_hash, $run->definition_hash], [$record->query_hash, $run->query_hash], [$record->source_hash, $run->source_hash], [$record->result_hash, $run->result_hash],
            [$record->scope_holding_organization_ids, $run->scope_holding_organization_ids], [$record->scope_project_ids, $run->scope_project_ids], [$record->scope_resources, $this->runScopeResources($run)], [$record->scope_timezone, $run->scope_timezone],
            [$record->snapshot_kind, $run->snapshot_kind], [$record->snapshot_id, $run->snapshot_id], [$record->snapshot_generated_at, $run->snapshot_generated_at], [$record->snapshot_stale_at, $run->snapshot_stale_at], [$record->snapshot_watermarks, $run->snapshot_watermarks], [$record->snapshot_classification, $run->snapshot_classification],
            [$record->snapshot_seal_key_id, $run->snapshot_seal_key_id], [$record->snapshot_seal_algorithm, $run->snapshot_seal_algorithm], [$record->snapshot_sealed_payload_hash, $run->snapshot_sealed_payload_hash], [$record->snapshot_seal_signature, $run->snapshot_seal_signature], [$record->snapshot_sealed_at, $run->snapshot_sealed_at],
            [$record->data_classification, $run->data_classification], [$record->sensitive_column_ids, $run->sensitive_column_ids], [$record->audit_column_ids, $run->audit_column_ids], [$record->totals_sensitive, $outputClassification['totals_sensitive'] ?? null], [$record->totals_audit, $outputClassification['totals_audit'] ?? null], [$record->provenance_audit, $outputClassification['provenance_audit'] ?? null],
            [$record->contract_version, $run->contract_version], [$record->formula_version, $run->formula_version], [$record->source_schema_version, $run->source_schema_version], [$record->renderer_version, $run->renderer_version],
        ];
    }

    private function runScopeResources(ReportRunRecord $run): array
    {
        $resources = $run->getAttribute('scope_resources');
        if (is_array($resources)) {
            return $resources;
        }

        $legacyResources = $run->getRawOriginal('scope_resource_ids');
        if (is_string($legacyResources)) {
            $decoded = json_decode($legacyResources, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && array_is_list($decoded)) {
                return $decoded;
            }
        }

        throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
    }

    private function identityValuesMatch(mixed $left, mixed $right): bool
    {
        if (is_string($left) && is_string($right)) {
            return hash_equals($left, $right);
        }
        if ($left instanceof DateTimeInterface && $right instanceof DateTimeInterface) {
            return $left->format('U.u') === $right->format('U.u');
        }

        return $left === $right;
    }

    private function assertRunFence(
        ReportExecutionContext $context,
        ReportRunExportSource $source,
        ReportRunRecord $record,
        ReportAuthorizationFence $fence,
    ): void {
        $subject = $fence->subject;
        $snapshot = $subject->snapshot;
        $runIdentityMatches = $subject->exportIdentityHash !== null
            && hash_equals(
                ReportRunAuthorizationIdentity::fromRecord($record)->value,
                $subject->exportIdentityHash->value,
            );
        $this->assertExactRecordScope($context, $record);
        if ($subject->aggregateKind !== ReportDispatchAggregate::RUN
            || ! hash_equals($record->id, $subject->aggregateId)
            || ! hash_equals($record->id, $source->run->id)
            || $snapshot === null
            || ! hash_equals((string) $record->definition_hash, $subject->definition->definitionHash->value)
            || ! hash_equals((string) $record->definition_hash, $source->query->definition->definitionHash->value)
            || ! hash_equals((string) $record->query_hash, $source->query->queryHash->value)
            || ! hash_equals((string) $record->source_hash, $source->snapshot->sourceHash->value)
            || ! hash_equals((string) $record->result_hash, $source->resultHash->value)
            || ! hash_equals((string) $record->snapshot_id, $snapshot->id)
            || ! hash_equals((string) $record->snapshot_id, $source->snapshot->id)
            || ! hash_equals((string) $record->source_hash, $snapshot->sourceHash->value)
            || ! hash_equals((string) $record->formula_version, $snapshot->formulaVersion)
            || ! $runIdentityMatches) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
    }

    private function assertExportFence(
        ReportExecutionContext $context,
        ReportExportRecord $record,
        ReportAuthorizationFence $fence,
    ): void {
        $subject = $fence->subject;
        $snapshot = $subject->snapshot;
        $status = ReportExportStatus::tryFrom((string) $record->status);
        $artifactRetained = in_array(
            $status,
            [ReportExportStatus::READY, ReportExportStatus::EXPIRED],
            true,
        );
        $artifactMatches = $artifactRetained
            ? $subject->artifactIdentityHash !== null
                && hash_equals((string) $record->artifact_checksum, $subject->artifactIdentityHash->value)
            : $subject->artifactIdentityHash === null;
        $exportIdentityMatches = $subject->exportIdentityHash !== null
            && hash_equals(
                ReportExportAuthorizationIdentity::fromRecord($record)->value,
                $subject->exportIdentityHash->value,
            );
        $this->assertExactRecordScope($context, $record);
        if ($subject->aggregateKind !== ReportDispatchAggregate::EXPORT
            || ! hash_equals((string) $record->id, $subject->aggregateId)
            || $snapshot === null
            || $subject->parentRunId === null
            || ! hash_equals((string) $record->run_id, $subject->parentRunId)
            || ! hash_equals((string) $record->definition_hash, $subject->definition->definitionHash->value)
            || ! hash_equals((string) $record->snapshot_id, $snapshot->id)
            || ! hash_equals((string) $record->source_hash, $snapshot->sourceHash->value)
            || ! hash_equals((string) $record->formula_version, $snapshot->formulaVersion)
            || $subject->exportFormat === null
            || $fence->exportFormat === null
            || ! hash_equals((string) $record->format, $subject->exportFormat)
            || ! hash_equals((string) $record->format, $fence->exportFormat)
            || ! $artifactMatches
            || ! $exportIdentityMatches) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
    }

    /**
     * @param  list<string>  $selectedColumns
     * @return list<ReportOperation>
     */
    private function requiredOperations(
        ReportExportRecord|ReportRunRecord $record,
        array $selectedColumns,
        ReportOperation $primary,
    ): array {
        $sensitiveColumns = $this->classificationColumnIds($record->sensitive_column_ids);
        $auditColumns = $this->classificationColumnIds($record->audit_column_ids);
        $operations = [$primary];
        if ($record->data_classification === ReportDataClassification::SENSITIVE->value
            || array_intersect($selectedColumns, $sensitiveColumns) !== []) {
            $operations[] = ReportOperation::VIEW_SENSITIVE;
        }
        if (array_intersect($selectedColumns, $auditColumns) !== []) {
            $operations[] = ReportOperation::VIEW_AUDIT;
        }

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function selectedColumns(ReportExportRecord $record): array
    {
        return $this->classificationColumnIds($record->selected_columns);
    }

    /**
     * @return list<string>
     */
    private function classificationColumnIds(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }
        foreach ($value as $columnId) {
            if (! is_string($columnId) || $columnId === '') {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }
        }

        return $value;
    }

    private function assertExactRecordScope(
        ReportExecutionContext $context,
        ReportExportRecord|ReportRunRecord $record,
    ): void {
        $stored = [
            'organization_id' => (int) $record->organization_id,
            'holding_organization_ids' => array_map('intval', (array) $record->scope_holding_organization_ids),
            'project_ids' => array_map('intval', (array) $record->scope_project_ids),
            'resources' => $record instanceof ReportRunRecord
                ? $this->runScopeResources($record)
                : (array) $record->scope_resources,
            'timezone' => (string) $record->scope_timezone,
        ];
        if ($context->scope->canonicalIdentity() !== $stored) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
    }

    private function find(ReportExecutionContext $context, string $id): ReportExportRecord
    {
        $record = ReportExportRecord::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($id)
            ->first();
        if (! $record instanceof ReportExportRecord) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
        $this->assertExactRecordScope($context, $record);

        return $record;
    }

    private function locked(ReportExecutionContext $context, string $id): ReportExportRecord
    {
        $record = ReportExportRecord::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
        if (! $record instanceof ReportExportRecord) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
        $this->assertExactRecordScope($context, $record);

        return $record;
    }

    private function cas(ReportExportRecord $record, ReportExportStatus $expected, array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value instanceof DateTimeImmutable) {
                $values[$key] = $this->timestamp($value);
            }
        }
        if (ReportExportRecord::query()->whereKey($record->id)->where('organization_id', $record->organization_id)->where('status', $expected->value)->update($values) !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_NOT_READY);
        }
    }

    private function assertLeaseInput(string $token, DateTimeImmutable $expiresAt, DateTimeImmutable $occurredAt): void
    {
        if (! Str::isUuid($token) || $expiresAt != $occurredAt->modify("+{$this->runtime->executionLeaseSeconds} seconds")) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }
    }

    private function hasLiveLease(ReportExportRecord $record, string $token, DateTimeImmutable $occurredAt): bool
    {
        $expiresAt = $record->execution_lease_expires_at;
        $heartbeatAt = $record->execution_heartbeat_at;

        return is_string($record->execution_lease_token) && hash_equals($record->execution_lease_token, $token) && $expiresAt instanceof DateTimeInterface && $heartbeatAt instanceof DateTimeInterface && DateTimeImmutable::createFromInterface($expiresAt) > $occurredAt && DateTimeImmutable::createFromInterface($expiresAt) == DateTimeImmutable::createFromInterface($heartbeatAt)->modify("+{$this->runtime->executionLeaseSeconds} seconds");
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function expired(mixed $value, DateTimeImmutable $occurredAt): bool
    {
        if (! $value instanceof DateTimeInterface) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return DateTimeImmutable::createFromInterface($value) <= $occurredAt;
    }

    private function remainingSeconds(mixed $value, DateTimeImmutable $occurredAt): int
    {
        if (! $value instanceof DateTimeInterface) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return max(0, (int) floor(
            (float) DateTimeImmutable::createFromInterface($value)->format('U.u')
            - (float) $occurredAt->format('U.u'),
        ));
    }

    private function serializableTransaction(Closure $callback): mixed
    {
        if (DB::connection()->transactionLevel() > 0) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return DB::transaction(function () use ($callback): mixed {
            DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');

            return $callback();
        }, 3);
    }

    private function isRetryable(ReportErrorCode $errorCode): bool
    {
        return in_array($errorCode, [
            ReportErrorCode::REPORT_RATE_LIMITED,
            ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
            ReportErrorCode::REPORT_DEPENDENCY_FAILED,
            ReportErrorCode::REPORT_INTERNAL_ERROR,
        ], true);
    }
}
