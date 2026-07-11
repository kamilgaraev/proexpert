<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use DateTimeImmutable;

final readonly class DocumentUnitExecutionOwnershipGuard
{
    /** @param array{int,int,int,int} $storedScope @param array{int,int,int,int} $claimScope */
    public function assertOwned(
        string $status,
        string $storedToken,
        string $claimToken,
        string $storedSourceVersion,
        string $claimSourceVersion,
        ?DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $now,
        array $storedScope,
        array $claimScope,
    ): void {
        if ($status !== DocumentProcessingUnitStatus::Running->value
            || $storedToken === '' || ! hash_equals($storedToken, $claimToken)
            || $storedSourceVersion !== $claimSourceVersion
            || $leaseExpiresAt === null || $leaseExpiresAt <= $now
            || $storedScope !== $claimScope) {
            throw new DocumentUnitProcessingException('unit_claim_lost');
        }
    }
}
