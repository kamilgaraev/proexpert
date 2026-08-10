<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptStateMachine;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisionPhysicalAttemptStateMachineTest extends TestCase
{
    #[Test]
    public function expired_pre_wire_lease_can_be_taken_over_by_one_new_owner(): void
    {
        $decision = (new VisionPhysicalAttemptStateMachine)->claim(
            new VisionPhysicalAttemptSnapshot(
                false,
                'pre_wire',
                ownerToken: 'dead-owner',
                leaseExpiresAt: new DateTimeImmutable('2026-08-10T10:00:00+03:00'),
            ),
            'new-owner',
            new DateTimeImmutable('2026-08-10T10:00:01+03:00'),
        );

        self::assertSame('takeover', $decision->action);
        self::assertSame('new-owner', $decision->ownerToken);
    }

    #[Test]
    public function active_pre_wire_or_wire_started_lease_never_grants_a_second_owner(): void
    {
        $machine = new VisionPhysicalAttemptStateMachine;
        $now = new DateTimeImmutable('2026-08-10T10:00:00+03:00');
        $expires = new DateTimeImmutable('2026-08-10T10:01:00+03:00');

        self::assertSame('busy', $machine->claim(new VisionPhysicalAttemptSnapshot(
            false, 'pre_wire', ownerToken: 'owner-a', leaseExpiresAt: $expires,
        ), 'owner-b', $now)->action);
        self::assertSame('busy', $machine->claim(new VisionPhysicalAttemptSnapshot(
            false, 'wire_started', ownerToken: 'owner-a', leaseExpiresAt: $expires,
        ), 'owner-b', $now)->action);
    }

    #[Test]
    public function expired_wire_started_attempt_is_terminal_ambiguous_not_reclaimable(): void
    {
        $decision = (new VisionPhysicalAttemptStateMachine)->claim(
            new VisionPhysicalAttemptSnapshot(
                false,
                'wire_started',
                ownerToken: 'dead-owner',
                leaseExpiresAt: new DateTimeImmutable('2026-08-10T10:00:00+03:00'),
            ),
            'new-owner',
            new DateTimeImmutable('2026-08-10T10:00:01+03:00'),
        );

        self::assertSame('ambiguous', $decision->action);
        self::assertNull($decision->ownerToken);
    }

    #[Test]
    public function persisted_response_is_replayed_and_legacy_reserved_is_ambiguous(): void
    {
        $machine = new VisionPhysicalAttemptStateMachine;
        $now = new DateTimeImmutable('2026-08-10T10:00:00+03:00');

        self::assertSame('replay', $machine->claim(new VisionPhysicalAttemptSnapshot(
            false, 'response_received', responsePayload: ['raw_body_base64' => 'e30='],
        ), 'owner', $now)->action);
        self::assertSame('ambiguous', $machine->claim(
            new VisionPhysicalAttemptSnapshot(false, 'reserved'),
            'owner',
            $now,
        )->action);
    }
}
