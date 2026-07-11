<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

final readonly class FinalizationClaim
{
    public function __construct(
        public int $id,
        public FinalizationEvent $event,
        public string $claimToken,
        public int $attempt,
    ) {}
}
