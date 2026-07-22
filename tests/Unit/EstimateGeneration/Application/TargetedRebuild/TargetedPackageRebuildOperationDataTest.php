<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application\TargetedRebuild;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetedPackageRebuildOperationDataTest extends TestCase
{
    #[Test]
    public function it_exposes_the_durable_targeted_rebuild_operation_contract(): void
    {
        self::assertTrue(class_exists(
            \App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationData::class,
        ));
    }

    #[Test]
    public function it_keeps_only_compact_rebuild_metadata_without_the_source_draft(): void
    {
        $operation = \App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationData::queued(
            operationId: '018f809a-e85e-7382-b419-00f5a7d7ab59',
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

        self::assertSame('queued', $operation->status);
        self::assertSame('roof', $operation->packageKey);
        self::assertSame([], $operation->resultDelta);
        self::assertFalse(property_exists($operation, 'draft'));
        self::assertFalse(property_exists($operation, 'prompt'));
        self::assertFalse(property_exists($operation, 'documents'));
    }

    #[Test]
    public function it_uses_a_lease_token_to_claim_one_operation_attempt(): void
    {
        $claimed = $this->queued()->withLease(
            leaseToken: '018f809a-e85e-7382-b419-00f5a7d7ab59',
            leaseExpiresAt: new \DateTimeImmutable('2026-07-22T12:30:00+00:00'),
        );

        self::assertSame('running', $claimed->status);
        self::assertSame(1, $claimed->attemptCount);
        self::assertSame('018f809a-e85e-7382-b419-00f5a7d7ab59', $claimed->leaseToken);
    }

    #[Test]
    public function it_releases_the_lease_when_only_the_compact_review_result_is_durable(): void
    {
        $reviewed = $this->queued()
            ->withLease('018f809a-e85e-7382-b419-00f5a7d7ab59', new \DateTimeImmutable('2026-07-22T12:30:00+00:00'))
            ->withReviewed(
                resultDelta: [
                    'target_package' => ['key' => 'roof'],
                    'target_before_fingerprint' => 'sha256:'.str_repeat('d', 64),
                    'target_after_fingerprint' => 'sha256:'.str_repeat('e', 64),
                    'non_target_fingerprints' => ['walls' => 'sha256:'.str_repeat('f', 64)],
                ],
                safeArbiterReview: ['mode' => 'shadow', 'status' => 'reviewed', 'outcome' => 'passed'],
            );

        self::assertSame('reviewed', $reviewed->status);
        self::assertNull($reviewed->leaseToken);
        self::assertSame('roof', $reviewed->resultDelta['target_package']['key']);
        self::assertSame('passed', $reviewed->safeArbiterReview['outcome']);
    }

    private function queued(): \App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationData
    {
        return \App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationData::queued(
            operationId: '018f809a-e85e-7382-b419-00f5a7d7ab59',
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
