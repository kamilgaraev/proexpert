<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class ArbitrationDecision
{
    /** @param list<string> $supportingClaimIds @param list<string> $evidenceRefs @param array<string,mixed>|null $canonicalClaim */
    public function __construct(
        public string $claimId,
        public string $status,
        public array $supportingClaimIds,
        public array $evidenceRefs,
        public string $reasonCode,
        public ?array $canonicalClaim,
        public string $reason = '',
    ) {}
}
