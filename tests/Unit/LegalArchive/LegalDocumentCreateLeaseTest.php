<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Sources\LegalDocumentCreateLeaseDecision;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalDocumentCreateLeaseTest extends TestCase
{
    public function test_crash_before_upload_is_reclaimed_as_retry_upload(): void
    {
        $decision = $this->decide('pending', '2026-07-20T11:59:59+00:00', false, 'retry_upload');

        self::assertSame('claim', $decision->decision);
        self::assertSame('retry_upload', $decision->retryAction);
    }

    public function test_crash_after_ready_version_before_finalize_does_not_upload_another_version(): void
    {
        $decision = $this->decide('pending', '2026-07-20T11:59:59+00:00', true, 'retry_upload');

        self::assertSame('claim', $decision->decision);
        self::assertSame('retry_finalize', $decision->retryAction);
    }

    public function test_lost_success_response_replays_completed_operation(): void
    {
        $decision = $this->decide('completed', null, true, null);

        self::assertSame('replay', $decision->decision);
        self::assertNull($decision->retryAction);
    }

    public function test_second_concurrent_claim_cannot_steal_active_lease_or_use_first_token(): void
    {
        $decision = $this->decide('pending', '2026-07-20T12:05:00+00:00', false, 'retry_upload');

        self::assertSame('in_progress', $decision->decision);
        self::assertTrue(LegalDocumentCreateLeaseDecision::ownsAttempt('attempt-one', 'attempt-one'));
        self::assertFalse(LegalDocumentCreateLeaseDecision::ownsAttempt('attempt-one', 'attempt-two'));
        self::assertFalse(LegalDocumentCreateLeaseDecision::ownsAttempt(null, 'attempt-two'));
    }

    public function test_nonstale_attempt_is_not_reclaimable(): void
    {
        self::assertSame(
            'in_progress',
            $this->decide('pending', '2026-07-20T12:00:01+00:00', false, 'retry_upload')->decision,
        );
    }

    public function test_fileless_crash_preserves_finalize_phase_without_ready_version(): void
    {
        $decision = $this->decide('pending', '2026-07-20T11:59:59+00:00', false, 'retry_finalize');

        self::assertSame('claim', $decision->decision);
        self::assertSame('retry_finalize', $decision->retryAction);
    }

    #[DataProvider('decisionProvider')]
    public function test_claim_decision_is_deterministic(
        string $status,
        ?string $leaseExpiresAt,
        bool $hasReadyVersion,
        string $expected,
        ?string $retryAction,
        ?string $persistedRetryAction = null,
    ): void {
        $decision = LegalDocumentCreateLeaseDecision::decide(
            $status,
            $leaseExpiresAt === null ? null : new DateTimeImmutable($leaseExpiresAt),
            new DateTimeImmutable('2026-07-20T12:00:00+00:00'),
            $hasReadyVersion,
            $persistedRetryAction,
        );

        self::assertSame($expected, $decision->decision);
        self::assertSame($retryAction, $decision->retryAction);
    }

    public static function decisionProvider(): array
    {
        return [
            'completed is replayed' => ['completed', null, false, 'replay', null],
            'active pending lease is not stolen' => ['pending', '2026-07-20T12:05:00+00:00', false, 'in_progress', null],
            'stale upload is reclaimable' => ['pending', '2026-07-20T11:59:59+00:00', false, 'claim', 'retry_upload'],
            'stale finalization is reclaimable without upload' => ['pending', '2026-07-20T11:59:59+00:00', true, 'claim', 'retry_finalize'],
            'failed upload is reclaimable' => ['failed', null, false, 'claim', 'retry_upload'],
            'failed finalization is reclaimable' => ['failed', null, true, 'claim', 'retry_finalize'],
            'fileless stale create keeps finalize phase' => ['pending', '2026-07-20T11:59:59+00:00', false, 'claim', 'retry_finalize', 'retry_finalize'],
        ];
    }

    private function decide(
        string $status,
        ?string $leaseExpiresAt,
        bool $hasReadyVersion,
        ?string $persistedRetryAction,
    ): LegalDocumentCreateLeaseDecision {
        return LegalDocumentCreateLeaseDecision::decide(
            $status,
            $leaseExpiresAt === null ? null : new DateTimeImmutable($leaseExpiresAt),
            new DateTimeImmutable('2026-07-20T12:00:00+00:00'),
            $hasReadyVersion,
            $persistedRetryAction,
        );
    }
}
