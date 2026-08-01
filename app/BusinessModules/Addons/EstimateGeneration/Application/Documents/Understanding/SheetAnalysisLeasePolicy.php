<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

use DateTimeImmutable;
use LogicException;

final readonly class SheetAnalysisLeasePolicy
{
    public const UNIT_LEASE_SECONDS = 2100;

    public const JOURNAL_LEASE_SECONDS = 2100;

    public const MAX_WIRE_SECONDS = 1800;

    public function renewedUnitLease(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('+%d seconds', self::UNIT_LEASE_SECONDS));
    }

    public function renewedJournalLease(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('+%d seconds', self::JOURNAL_LEASE_SECONDS));
    }

    public function assertCanStartWire(DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $now): void
    {
        if ($leaseExpiresAt <= $now->modify(sprintf('+%d seconds', self::MAX_WIRE_SECONDS))) {
            throw new LogicException('document_unit_lease_renewal_required');
        }
    }

    public function canFinalize(?DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $now): bool
    {
        return $leaseExpiresAt !== null && $leaseExpiresAt > $now;
    }

    public function canReclaim(string $status, ?DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $now): bool
    {
        return in_array($status, ['queued', 'failed'], true)
            || ($status === 'claimed' && $leaseExpiresAt !== null && $leaseExpiresAt <= $now);
    }
}
