<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

final readonly class GeometryFusionResult
{
    public function __construct(public array $elements, public array $sourceElements, public array $issues) {}

    public function toArray(): array
    {
        return ['elements' => array_map(static fn (FusedGeometryElementData $item): array => $item->toArray(), $this->elements), 'source_elements' => array_map(static fn (FusedGeometryElementData $item): array => $item->toArray(), $this->sourceElements), 'issues' => $this->issues];
    }
}
