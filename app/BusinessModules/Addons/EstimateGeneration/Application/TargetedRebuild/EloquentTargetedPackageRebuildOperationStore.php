<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTargetedRebuildOperation;
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
        } catch (QueryException) {
            $created = $this->query()->where('idempotency_key', $operation->idempotencyKey)->first();
            if (! $created instanceof EstimateGenerationTargetedRebuildOperation) {
                throw new LogicException('Targeted rebuild operation was not persisted.');
            }

            return new TargetedPackageRebuildOperationStoreResult($this->matchingData($created, $operation), false);
        }

        return new TargetedPackageRebuildOperationStoreResult($this->matchingData($created, $operation), true);
    }

    private function query(): \Illuminate\Database\Eloquent\Builder
    {
        return EstimateGenerationTargetedRebuildOperation::query();
    }

    private function matchingData(
        EstimateGenerationTargetedRebuildOperation $model,
        TargetedPackageRebuildOperationData $expected,
    ): TargetedPackageRebuildOperationData {
        if ((string) $model->operation_id !== $expected->operationId
            || (string) $model->idempotency_key !== $expected->idempotencyKey
            || (int) $model->organization_id !== $expected->organizationId
            || (int) $model->project_id !== $expected->projectId
            || (int) $model->session_id !== $expected->sessionId
            || (int) $model->expected_state_version !== $expected->expectedStateVersion
            || (string) $model->source_input_version !== $expected->sourceInputVersion
            || (string) $model->root_input_hash !== $expected->rootInputHash
            || (string) $model->source_draft_fingerprint !== $expected->sourceDraftFingerprint
            || (string) $model->package_key !== $expected->packageKey
            || (string) $model->status !== $expected->status
            || (int) $model->attempt_count !== $expected->attemptCount
            || (is_array($model->result_delta) ? $model->result_delta : []) !== $expected->resultDelta
            || (is_array($model->safe_arbiter_review) ? $model->safe_arbiter_review : []) !== $expected->safeArbiterReview) {
            throw new LogicException('Targeted rebuild idempotency key is bound to different content.');
        }

        return $expected;
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
