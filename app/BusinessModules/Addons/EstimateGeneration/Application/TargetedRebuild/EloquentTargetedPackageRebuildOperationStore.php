<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTargetedRebuildOperation;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use LogicException;

final class EloquentTargetedPackageRebuildOperationStore implements TargetedPackageRebuildOperationStore
{
    public function createOrFind(TargetedPackageRebuildOperationData $operation): TargetedPackageRebuildOperationStoreResult
    {
        $existing = $this->query()->where('idempotency_key', $operation->idempotencyKey)->first();
        if ($existing instanceof EstimateGenerationTargetedRebuildOperation) {
            return new TargetedPackageRebuildOperationStoreResult($this->matchingData($existing, $operation), false);
        }

        try {
            $created = $this->query()->create($this->attributes($operation));
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $created = $this->query()->where('idempotency_key', $operation->idempotencyKey)->first();
            if (! $created instanceof EstimateGenerationTargetedRebuildOperation) {
                throw new LogicException('Targeted rebuild operation was not persisted.');
            }

            return new TargetedPackageRebuildOperationStoreResult($this->matchingData($created, $operation), false);
        }

        return new TargetedPackageRebuildOperationStoreResult($this->matchingData($created, $operation), true);
    }

    public function find(string $operationId): ?TargetedPackageRebuildOperationData
    {
        $model = $this->query()->whereKey($operationId)->first();

        return $model instanceof EstimateGenerationTargetedRebuildOperation
            ? $this->data($model)
            : null;
    }

    public function claimQueued(string $operationId, string $leaseToken, DateTimeImmutable $leaseExpiresAt): ?TargetedPackageRebuildOperationData
    {
        $updated = $this->query()
            ->whereKey($operationId)
            ->where('status', 'queued')
            ->whereNull('lease_token')
            ->update([
                'status' => 'running',
                'lease_token' => $leaseToken,
                'lease_expires_at' => $leaseExpiresAt,
                'attempt_count' => DB::raw('attempt_count + 1'),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return null;
        }

        return $this->find($operationId);
    }

    public function save(TargetedPackageRebuildOperationData $operation): void
    {
        $model = $this->query()->whereKey($operation->operationId)->first();
        if (! $model instanceof EstimateGenerationTargetedRebuildOperation) {
            throw new LogicException('Targeted rebuild operation is unavailable.');
        }

        $model->forceFill($this->attributes($operation));
        if ($operation->status === 'reviewed') {
            $model->reviewed_at = now();
            $model->finished_at = null;
        } elseif (in_array($operation->status, ['committed', 'human_review', 'stale', 'cancelled'], true)) {
            $model->finished_at = now();
        }
        $model->save();
    }

    private function query(): \Illuminate\Database\Eloquent\Builder
    {
        return EstimateGenerationTargetedRebuildOperation::query();
    }

    private function matchingData(
        EstimateGenerationTargetedRebuildOperation $model,
        TargetedPackageRebuildOperationData $expected,
    ): TargetedPackageRebuildOperationData {
        $stored = $this->data($model);

        if ($stored->idempotencyKey !== $expected->idempotencyKey
            || $stored->organizationId !== $expected->organizationId
            || $stored->projectId !== $expected->projectId
            || $stored->sessionId !== $expected->sessionId
            || $stored->expectedStateVersion !== $expected->expectedStateVersion
            || $stored->sourceInputVersion !== $expected->sourceInputVersion
            || $stored->rootInputHash !== $expected->rootInputHash
            || $stored->sourceDraftFingerprint !== $expected->sourceDraftFingerprint
            || $stored->packageKey !== $expected->packageKey) {
            throw new LogicException('Targeted rebuild idempotency key is bound to different content.');
        }

        return $stored;
    }

    private function data(EstimateGenerationTargetedRebuildOperation $model): TargetedPackageRebuildOperationData
    {
        return TargetedPackageRebuildOperationData::fromPersisted(
            operationId: (string) $model->operation_id,
            idempotencyKey: (string) $model->idempotency_key,
            organizationId: (int) $model->organization_id,
            projectId: (int) $model->project_id,
            sessionId: (int) $model->session_id,
            expectedStateVersion: (int) $model->expected_state_version,
            sourceInputVersion: (string) $model->source_input_version,
            rootInputHash: (string) $model->root_input_hash,
            sourceDraftFingerprint: (string) $model->source_draft_fingerprint,
            packageKey: (string) $model->package_key,
            status: (string) $model->status,
            leaseToken: is_string($model->lease_token) ? $model->lease_token : null,
            leaseExpiresAt: $this->leaseExpiresAt($model),
            attemptCount: (int) $model->attempt_count,
            resultDelta: is_array($model->result_delta) ? $model->result_delta : [],
            safeArbiterReview: is_array($model->safe_arbiter_review) ? $model->safe_arbiter_review : [],
        );
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23505';
    }

    private function leaseExpiresAt(EstimateGenerationTargetedRebuildOperation $model): ?DateTimeImmutable
    {
        $value = $model->getRawOriginal('lease_expires_at');
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }
        if (! is_string($value)) {
            throw new LogicException('Targeted rebuild operation lease expiry is invalid.');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $exception) {
            throw new LogicException('Targeted rebuild operation lease expiry is invalid.', 0, $exception);
        }
    }

    private function attributes(TargetedPackageRebuildOperationData $operation): array
    {
        return [
            'operation_id' => $operation->operationId,
            'idempotency_key' => $operation->idempotencyKey,
            'organization_id' => $operation->organizationId,
            'project_id' => $operation->projectId,
            'session_id' => $operation->sessionId,
            'expected_state_version' => $operation->expectedStateVersion,
            'source_input_version' => $operation->sourceInputVersion,
            'root_input_hash' => $operation->rootInputHash,
            'source_draft_fingerprint' => $operation->sourceDraftFingerprint,
            'package_key' => $operation->packageKey,
            'status' => $operation->status,
            'lease_token' => $operation->leaseToken,
            'lease_expires_at' => $operation->leaseExpiresAt,
            'attempt_count' => $operation->attemptCount,
            'result_delta' => $operation->resultDelta,
            'safe_arbiter_review' => $operation->safeArbiterReview,
        ];
    }
}
