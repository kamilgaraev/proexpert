<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisLeasePolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SheetAnalysisLeasePolicyTest extends TestCase
{
    #[Test]
    public function sequential_primary_and_targeted_wires_require_a_fresh_unit_lease_for_each_call(): void
    {
        $policy = new SheetAnalysisLeasePolicy;
        $startedAt = new DateTimeImmutable('2026-08-01T10:00:00+00:00');
        $primaryLease = $policy->renewedUnitLease($startedAt);

        $policy->assertCanStartWire($primaryLease, $startedAt);

        $afterPrimary = $startedAt->modify('+1800 seconds');
        self::expectExceptionObject(new \LogicException('document_unit_lease_renewal_required'));
        $policy->assertCanStartWire($primaryLease, $afterPrimary);
    }

    #[Test]
    public function renewed_lease_keeps_targeted_wire_inside_its_own_bounded_window(): void
    {
        $policy = new SheetAnalysisLeasePolicy;
        $afterPrimary = new DateTimeImmutable('2026-08-01T10:30:00+00:00');
        $targetedLease = $policy->renewedUnitLease($afterPrimary);

        $policy->assertCanStartWire($targetedLease, $afterPrimary);
        self::assertSame('2026-08-01T11:05:00+00:00', $targetedLease->format(DATE_ATOM));
    }

    #[Test]
    public function expired_owner_cannot_publish_after_another_worker_can_reclaim_the_journal_entry(): void
    {
        $policy = new SheetAnalysisLeasePolicy;
        $expiredAt = new DateTimeImmutable('2026-08-01T10:35:00+00:00');

        self::assertTrue($policy->canReclaim('claimed', $expiredAt, $expiredAt));
        self::assertFalse($policy->canFinalize($expiredAt, $expiredAt));
    }
}
