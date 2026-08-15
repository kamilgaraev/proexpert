<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class ObservationClaimBatch
{
    /** @param list<ObservationClaim> $claims @param list<array{role:string,index:int|null,reason_code:string}> $quarantined */
    public function __construct(
        public array $claims,
        public array $quarantined,
    ) {}
}
