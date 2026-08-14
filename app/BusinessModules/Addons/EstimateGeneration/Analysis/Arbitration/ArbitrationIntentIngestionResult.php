<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class ArbitrationIntentIngestionResult
{
    /**
     * @param  list<ArbitrationDecision>  $accepted
     * @param  list<array{index:int,reason:string}>  $quarantined
     */
    public function __construct(
        public array $accepted,
        public array $quarantined,
    ) {}
}
