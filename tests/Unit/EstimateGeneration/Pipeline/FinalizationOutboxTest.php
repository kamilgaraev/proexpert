<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\FinalizationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryFinalizationOutbox;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FinalizationOutboxTest extends TestCase
{
    public function test_enqueue_is_idempotent_and_active_lease_excludes_delivery(): void
    {
        $outbox = new InMemoryFinalizationOutbox;
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $event = FinalizationEvent::completed(10, 20, 30, '018f4a20-3f4c-7a11-8a22-123456789abc');

        $outbox->enqueue($event, $now);
        $outbox->enqueue($event, $now);

        $first = $outbox->claim($now, $now->modify('+5 minutes'));
        self::assertNotNull($first);
        self::assertSame($event->idempotencyKey, $first->event->idempotencyKey);
        self::assertNull($outbox->claim($now->modify('+1 minute'), $now->modify('+6 minutes')));
    }

    public function test_expired_delivery_is_reclaimed_and_completed_once(): void
    {
        $outbox = new InMemoryFinalizationOutbox;
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $event = FinalizationEvent::completed(10, 20, 30, '018f4a20-3f4c-7a11-8a22-123456789abc');
        $outbox->enqueue($event, $now);

        $first = $outbox->claim($now, $now->modify('+1 minute'));
        self::assertNotNull($first);
        $second = $outbox->claim($now->modify('+2 minutes'), $now->modify('+3 minutes'));
        self::assertNotNull($second);
        self::assertNotSame($first->claimToken, $second->claimToken);
        self::assertFalse($outbox->complete($first, $now->modify('+2 minutes')));
        self::assertTrue($outbox->complete($second, $now->modify('+2 minutes')));
        self::assertNull($outbox->claim($now->modify('+4 minutes'), $now->modify('+5 minutes')));
    }

    public function test_failed_delivery_is_released_with_backoff(): void
    {
        $outbox = new InMemoryFinalizationOutbox;
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $outbox->enqueue(FinalizationEvent::completed(10, 20, 30, '018f4a20-3f4c-7a11-8a22-123456789abc'), $now);
        $claim = $outbox->claim($now, $now->modify('+1 minute'));
        self::assertNotNull($claim);

        self::assertTrue($outbox->release($claim, $now->modify('+5 minutes')));
        self::assertNull($outbox->claim($now->modify('+4 minutes'), $now->modify('+6 minutes')));
        self::assertNotNull($outbox->claim($now->modify('+5 minutes'), $now->modify('+6 minutes')));
    }

    public function test_crash_after_business_send_before_mark_is_retried_without_duplicate_business_delivery(): void
    {
        $outbox = new InMemoryFinalizationOutbox;
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $event = FinalizationEvent::completed(10, 20, 30, '018f4a20-3f4c-7a11-8a22-123456789abc');
        $outbox->enqueue($event, $now);
        $delivered = [];
        $send = static function (string $key) use (&$delivered): void {
            $delivered[$key] = true;
        };

        $crashed = $outbox->claim($now, $now->modify('+1 minute'));
        self::assertNotNull($crashed);
        $send($crashed->event->idempotencyKey);

        $recovered = $outbox->claim($now->modify('+2 minutes'), $now->modify('+3 minutes'));
        self::assertNotNull($recovered);
        $send($recovered->event->idempotencyKey);
        self::assertCount(1, $delivered);
        self::assertTrue($outbox->complete($recovered, $now->modify('+2 minutes')));
    }
}
