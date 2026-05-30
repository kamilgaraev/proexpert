<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives;

final readonly class NormativeCandidateDecisionContextData
{
    /**
     * @param array<int, string> $hardWarnings
     * @param array<int, string> $reviewWarnings
     */
    public function __construct(
        public bool $unitCompatible,
        public bool $scopeCompatible,
        public int $resourceCount,
        public int $pricedResourceCount,
        public array $hardWarnings,
        public array $reviewWarnings,
    ) {}
}
