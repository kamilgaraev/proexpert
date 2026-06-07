<?php

declare(strict_types=1);

namespace Tests\Unit\OneCExchange;

use App\Services\OneCExchange\Support\OneCExchangeRetryPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class OneCExchangeRetryPolicyTest extends TestCase
{
    public function test_technical_failures_are_retried_with_progressive_backoff(): void
    {
        $policy = new OneCExchangeRetryPolicy();
        $now = new DateTimeImmutable('2026-06-07 10:00:00');

        self::assertSame(60, $policy->delaySecondsForAttempt(1));
        self::assertSame(300, $policy->delaySecondsForAttempt(2));
        self::assertSame(900, $policy->delaySecondsForAttempt(3));
        self::assertSame(3600, $policy->delaySecondsForAttempt(4));
        self::assertSame(10800, $policy->delaySecondsForAttempt(5));

        $decision = $policy->decide(
            status: 'failed',
            failureType: 'timeout',
            attemptNumber: 2,
            maxAttempts: 5,
            accountingStatus: null,
            sourceIsActual: true,
            now: $now,
        );

        self::assertTrue($decision->retryable);
        self::assertFalse($decision->deadLetter);
        self::assertSame('2026-06-07 10:15:00', $decision->nextRetryAt?->format('Y-m-d H:i:s'));
    }

    public function test_business_errors_posted_documents_and_stale_sources_are_not_retried(): void
    {
        $policy = new OneCExchangeRetryPolicy();
        $now = new DateTimeImmutable('2026-06-07 10:00:00');

        foreach ([
            ['failed', 'business_validation', null, true],
            ['failed', 'timeout', 'posted', true],
            ['failed', 'timeout', null, false],
        ] as [$status, $failureType, $accountingStatus, $sourceIsActual]) {
            $decision = $policy->decide(
                status: $status,
                failureType: $failureType,
                attemptNumber: 1,
                maxAttempts: 5,
                accountingStatus: $accountingStatus,
                sourceIsActual: $sourceIsActual,
                now: $now,
            );

            self::assertFalse($decision->retryable);
            self::assertNull($decision->nextRetryAt);
        }
    }

    public function test_exhausted_technical_operation_goes_to_dead_letter(): void
    {
        $policy = new OneCExchangeRetryPolicy();

        $decision = $policy->decide(
            status: 'failed',
            failureType: 'server_error',
            attemptNumber: 5,
            maxAttempts: 5,
            accountingStatus: null,
            sourceIsActual: true,
            now: new DateTimeImmutable('2026-06-07 10:00:00'),
        );

        self::assertFalse($decision->retryable);
        self::assertTrue($decision->deadLetter);
        self::assertSame('Превышен лимит повторных доставок.', $decision->reason);
    }
}
