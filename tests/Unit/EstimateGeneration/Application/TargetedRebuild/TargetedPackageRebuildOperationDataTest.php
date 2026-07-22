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
                safeArbiterReview: $this->safeReview(),
            );

        self::assertSame('reviewed', $reviewed->status);
        self::assertNull($reviewed->leaseToken);
        self::assertSame('roof', $reviewed->resultDelta['target_package']['key']);
        self::assertSame('passed', $reviewed->safeArbiterReview['outcome']);
    }

    #[Test]
    public function it_rejects_a_review_transition_without_a_valid_running_claim(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->queued()->withReviewed($this->resultDelta(), $this->safeReview());
    }

    #[Test]
    public function it_allows_only_explicit_cancel_and_stale_terminal_paths_before_review(): void
    {
        $cancelled = $this->queued()->withCancelled();
        $stale = $this->queued()
            ->withLease('018f809a-e85e-7382-b419-00f5a7d7ab59', new \DateTimeImmutable('2026-07-22T12:30:00+00:00'))
            ->withStale();

        self::assertSame('cancelled', $cancelled->status);
        self::assertSame('stale', $stale->status);
        self::assertNull($cancelled->leaseToken);
        self::assertNull($stale->leaseToken);
    }

    #[Test]
    public function it_refuses_to_lease_a_terminal_operation_or_review_without_a_lease(): void
    {
        $this->expectException(\LogicException::class);

        $this->queued()->withCancelled()->withLease(
            '018f809a-e85e-7382-b419-00f5a7d7ab59',
            new \DateTimeImmutable('2026-07-22T12:30:00+00:00'),
        );
    }

    #[Test]
    public function it_refuses_to_reopen_a_reviewed_operation_for_a_second_lease(): void
    {
        $reviewed = $this->queued()
            ->withLease('018f809a-e85e-7382-b419-00f5a7d7ab59', new \DateTimeImmutable('2026-07-22T12:30:00+00:00'))
            ->withReviewed($this->resultDelta(), $this->safeReview());

        $this->expectException(\LogicException::class);
        $reviewed->withLease(
            '018f809a-e85e-7382-b419-00f5a7d7ab59',
            new \DateTimeImmutable('2026-07-22T12:45:00+00:00'),
        );
    }

    #[Test]
    public function it_exposes_reviewed_recovery_only_as_a_commit_input_without_changing_the_review_state(): void
    {
        $reviewed = $this->queued()
            ->withLease('018f809a-e85e-7382-b419-00f5a7d7ab59', new \DateTimeImmutable('2026-07-22T12:30:00+00:00'))
            ->withReviewed($this->resultDelta(), $this->safeReview());

        $recovery = $reviewed->commitRecovery();

        self::assertSame('reviewed', $reviewed->status);
        self::assertSame('018f809a-e85e-7382-b419-00f5a7d7ab59', $recovery->operationId);
        self::assertSame('roof', $recovery->packageKey);
        self::assertSame('roof', $recovery->resultDelta['target_package']['key']);
    }

    #[Test]
    public function it_refuses_to_commit_before_the_reviewed_state_is_durable(): void
    {
        $this->expectException(\LogicException::class);

        $this->queued()->withCommitted();
    }

    #[Test]
    public function it_rejects_nested_raw_payloads_from_the_compact_result_and_safe_review(): void
    {
        $claimed = $this->queued()->withLease(
            '018f809a-e85e-7382-b419-00f5a7d7ab59',
            new \DateTimeImmutable('2026-07-22T12:30:00+00:00'),
        );
        $unsafeResult = $this->resultDelta();
        $unsafeResult['target_package']['sections'] = [[
            'key' => 'roof-section',
            'work_items' => [[
                'key' => 'roof-work',
                'draft_payload' => ['secret' => 'must-not-persist'],
            ]],
        ]];

        try {
            $claimed->withReviewed($unsafeResult, $this->safeReview());
            self::fail('Nested draft payload must be rejected.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $unsafeReview = $this->safeReview();
        $unsafeReview['remediation']['prompt_payload'] = ['text' => 'must-not-persist'];

        $this->expectException(\InvalidArgumentException::class);
        $claimed->withReviewed($this->resultDelta(), $unsafeReview);
    }

    #[Test]
    public function it_rejects_unknown_nested_fields_even_when_they_do_not_match_a_forbidden_name(): void
    {
        $claimed = $this->queued()->withLease(
            '018f809a-e85e-7382-b419-00f5a7d7ab59',
            new \DateTimeImmutable('2026-07-22T12:30:00+00:00'),
        );
        $unsafeResult = $this->resultDelta();
        $unsafeResult['target_package']['sections'] = [[
            'key' => 'roof-section',
            'work_items' => [[
                'key' => 'roof-work',
                'normative_match' => [
                    'status' => 'matched',
                    'decision' => ['status' => 'accepted', 'opaque' => 'not-a-projection'],
                ],
            ]],
        ]];

        $this->expectException(\InvalidArgumentException::class);
        $claimed->withReviewed($unsafeResult, $this->safeReview());
    }

    #[Test]
    public function it_refuses_an_unavailable_review_as_a_passing_rebuild_result(): void
    {
        $claimed = $this->queued()->withLease(
            '018f809a-e85e-7382-b419-00f5a7d7ab59',
            new \DateTimeImmutable('2026-07-22T12:30:00+00:00'),
        );
        $unavailable = $this->safeReview();
        $unavailable['status'] = 'unavailable';

        $this->expectException(\InvalidArgumentException::class);
        $claimed->withReviewed($this->resultDelta(), $unavailable);
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

    /** @return array<string, mixed> */
    private function resultDelta(): array
    {
        return [
            'target_package' => ['key' => 'roof'],
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
            'findings' => [],
            'remediation' => [
                'root_input_hash' => 'sha256:'.str_repeat('b', 64),
                'target_package_keys' => ['roof'],
                'rebuild_attempted' => true,
                'phase' => 'reviewed',
                'review_outcome' => 'passed',
            ],
        ];
    }
}
