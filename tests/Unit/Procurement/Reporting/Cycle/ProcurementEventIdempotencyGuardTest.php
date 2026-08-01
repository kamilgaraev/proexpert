<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementEventIdempotencyGuard;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProcurementEventIdempotencyGuardTest extends TestCase
{
    public function test_exact_replay_is_idempotent(): void
    {
        $hash = hash('sha256', 'same transition');

        self::assertTrue((new ProcurementEventIdempotencyGuard())->isExactReplay($hash, $hash));
    }

    public function test_absent_event_is_not_a_replay(): void
    {
        self::assertFalse((new ProcurementEventIdempotencyGuard())->isExactReplay(null, hash('sha256', 'new')));
    }

    public function test_same_identity_with_different_payload_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('procurement_process_event_idempotency_conflict');

        (new ProcurementEventIdempotencyGuard())->isExactReplay(
            hash('sha256', 'first payload'),
            hash('sha256', 'different payload'),
        );
    }
}
