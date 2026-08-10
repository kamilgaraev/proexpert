<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt;

use DateTimeImmutable;

final class VisionPhysicalAttemptStateMachine
{
    public function claim(
        VisionPhysicalAttemptSnapshot $snapshot,
        string $ownerToken,
        DateTimeImmutable $now,
    ): VisionPhysicalAttemptClaimDecision {
        if (in_array($snapshot->state, ['response_received', 'completed'], true)) {
            return new VisionPhysicalAttemptClaimDecision('replay');
        }
        if (in_array($snapshot->state, ['ambiguous', 'reserved'], true)) {
            return new VisionPhysicalAttemptClaimDecision('ambiguous');
        }
        if ($snapshot->state === 'pre_wire') {
            if ($snapshot->ownerToken === $ownerToken) {
                return new VisionPhysicalAttemptClaimDecision('owned', $ownerToken);
            }
            if ($snapshot->leaseExpiresAt !== null && $snapshot->leaseExpiresAt <= $now) {
                return new VisionPhysicalAttemptClaimDecision('takeover', $ownerToken);
            }

            return new VisionPhysicalAttemptClaimDecision('busy');
        }
        if ($snapshot->state === 'wire_started') {
            if ($snapshot->ownerToken === $ownerToken) {
                return new VisionPhysicalAttemptClaimDecision('owned', $ownerToken);
            }
            if ($snapshot->leaseExpiresAt !== null && $snapshot->leaseExpiresAt <= $now) {
                return new VisionPhysicalAttemptClaimDecision('ambiguous');
            }

            return new VisionPhysicalAttemptClaimDecision('busy');
        }

        return new VisionPhysicalAttemptClaimDecision('ambiguous');
    }
}
