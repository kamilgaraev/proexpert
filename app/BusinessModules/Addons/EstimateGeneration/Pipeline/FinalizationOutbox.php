<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DateTimeImmutable;

interface FinalizationOutbox
{
    public function enqueue(FinalizationEvent $event, DateTimeImmutable $availableAt): void;

    public function claim(DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?FinalizationClaim;

    public function complete(FinalizationClaim $claim, DateTimeImmutable $deliveredAt): bool;

    public function release(FinalizationClaim $claim, DateTimeImmutable $availableAt): bool;
}
