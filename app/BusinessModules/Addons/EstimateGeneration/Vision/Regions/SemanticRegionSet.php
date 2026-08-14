<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Regions;

final readonly class SemanticRegionSet
{
    /** @param list<SemanticRegion> $regions @param list<array{index:int,reason:string}> $quarantined */
    public function __construct(
        public array $regions,
        public array $quarantined,
        public int $aggregatePixels,
    ) {}
}
