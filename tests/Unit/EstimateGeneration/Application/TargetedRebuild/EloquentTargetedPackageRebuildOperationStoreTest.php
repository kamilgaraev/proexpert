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

    #[Test]
    public function it_rehydrates_a_reviewed_operation_after_jsonb_reorders_object_keys(): void
    {
        $stored = $this->operation('018f809a-e85e-7382-b419-00f5a7d7ab59')
            ->withLease(
                '018f809a-e85e-7382-b419-00f5a7d7ab5a',
                new DateTimeImmutable('2026-07-22T12:30:00+00:00'),
            )
            ->withReviewed($this->resultDelta(), $this->safeReview());
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
            'lease_expires_at' => $stored->leaseExpiresAt,
            'attempt_count' => $stored->attemptCount,
            'result_delta' => $this->canonicalJsonb($stored->resultDelta),
            'safe_arbiter_review' => $this->canonicalJsonb($stored->safeArbiterReview),
        ]);
        $method = new ReflectionMethod(EloquentTargetedPackageRebuildOperationStore::class, 'matchingData');

        $rehydrated = $method->invoke(
            new EloquentTargetedPackageRebuildOperationStore,
            $model,
            $this->operation('018f809a-e85e-7382-b419-00f5a7d7ab5b'),
        );

        self::assertInstanceOf(TargetedPackageRebuildOperationData::class, $rehydrated);
        self::assertSame($stored->operationId, $rehydrated->operationId);
        self::assertSame('reviewed', $rehydrated->status);
        self::assertSame('roof-section', $rehydrated->resultDelta['target_package']['sections'][0]['key']);
        self::assertSame('roof-second-section', $rehydrated->resultDelta['target_package']['sections'][1]['key']);
        self::assertSame('missing_component', $rehydrated->safeArbiterReview['findings'][0]['reason_code']);
        self::assertSame('evidence_required', $rehydrated->safeArbiterReview['findings'][1]['reason_code']);
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

    /** @return array<string, mixed> */
    private function resultDelta(): array
    {
        return [
            'target_package' => [
                'key' => 'roof',
                'sections' => [[
                    'key' => 'roof-section',
                    'work_items' => [['key' => 'roof-work']],
                ], [
                    'key' => 'roof-second-section',
                    'work_items' => [['key' => 'roof-second-work']],
                ]],
            ],
            'target_before_fingerprint' => 'sha256:'.str_repeat('d', 64),
            'target_after_fingerprint' => 'sha256:'.str_repeat('e', 64),
            'non_target_fingerprints' => ['walls' => 'sha256:'.str_repeat('f', 64)],
        ];
    }

    /** @return array<string, mixed> */
    private function safeReview(): array
    {
        return [
            'mode' => 'shadow',
            'status' => 'reviewed',
            'outcome' => 'passed',
            'input_hash' => 'sha256:'.str_repeat('b', 64),
            'schema_version' => 'completeness-arbiter:v1',
            'prompt_version' => 'completeness-arbiter:v1',
            'model' => 'openai/gpt-5-mini',
            'findings' => [[
                'scope_key' => 'roof',
                'package_keys' => ['roof'],
                'evidence_refs' => ['evidence:roof'],
                'action' => 'rebuild',
                'reason_code' => 'missing_component',
            ], [
                'scope_key' => 'walls',
                'package_keys' => ['walls'],
                'evidence_refs' => ['evidence:walls'],
                'action' => 'review',
                'reason_code' => 'evidence_required',
            ]],
        ];
    }

    private function canonicalJsonb(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalJsonb($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalJsonb($item), $value);
    }
}
