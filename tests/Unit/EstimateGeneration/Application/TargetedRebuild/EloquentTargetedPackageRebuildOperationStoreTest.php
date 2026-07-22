<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\EloquentTargetedPackageRebuildOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationData;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTargetedRebuildOperation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EloquentTargetedPackageRebuildOperationStoreTest extends TestCase
{
    #[Test]
    public function it_returns_the_existing_progressed_operation_for_repeated_semantic_requests(): void
    {
        $stored = $this->operation('018f809a-e85e-7382-b419-00f5a7d7ab59')->withLease(
            '018f809a-e85e-7382-b419-00f5a7d7ab5a',
            new DateTimeImmutable('2026-07-22T12:30:00+00:00'),
        );
        $model = new EstimateGenerationTargetedRebuildOperation;
        $model->forceFill([
            'operation_id' => $stored->operationId,
            'idempotency_key' => $stored->idempotencyKey,
            'organization_id' => $stored->organizationId,
            'project_id' => $stored->projectId,
            'session_id' => $stored->sessionId,
            'expected_state_version' => $stored->expectedStateVersion,
            'source_input_version' => $stored->sourceInputVersion,
            'root_input_hash' => $stored->rootInputHash,
            'source_draft_fingerprint' => $stored->sourceDraftFingerprint,
            'package_key' => $stored->packageKey,
            'status' => $stored->status,
            'lease_token' => $stored->leaseToken,
            'attempt_count' => $stored->attemptCount,
            'result_delta' => $stored->resultDelta,
            'safe_arbiter_review' => $stored->safeArbiterReview,
        ]);
        $attributes = $model->getAttributes();
        $attributes['lease_expires_at'] = $stored->leaseExpiresAt?->format(DATE_ATOM);
        $model->setRawAttributes($attributes, true);
        $method = new ReflectionMethod(EloquentTargetedPackageRebuildOperationStore::class, 'matchingData');
        $store = new EloquentTargetedPackageRebuildOperationStore;

        $first = $method->invoke($store, $model, $this->operation('018f809a-e85e-7382-b419-00f5a7d7ab5b'));
        $second = $method->invoke($store, $model, $this->operation('018f809a-e85e-7382-b419-00f5a7d7ab5c'));

        self::assertInstanceOf(TargetedPackageRebuildOperationData::class, $first);
        self::assertSame($stored->operationId, $first->operationId);
        self::assertSame('running', $first->status);
        self::assertSame(1, $first->attemptCount);
        self::assertSame($stored->operationId, $second->operationId);
        self::assertSame('running', $second->status);
    }

    private function operation(string $operationId): TargetedPackageRebuildOperationData
    {
        return TargetedPackageRebuildOperationData::queued(
            operationId: $operationId,
            idempotencyKey: hash('sha256', 'session|8|source|root|roof'),
            organizationId: 4,
            projectId: 7,
            sessionId: 11,
            expectedStateVersion: 8,
            sourceInputVersion: 'sha256:'.str_repeat('a', 64),
            rootInputHash: 'sha256:'.str_repeat('b', 64),
            sourceDraftFingerprint: 'sha256:'.str_repeat('c', 64),
            packageKey: 'roof',
        );
    }
}
