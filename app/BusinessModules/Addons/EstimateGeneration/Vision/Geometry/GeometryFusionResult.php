<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

final readonly class GeometryFusionResult
{
    public function __construct(public array $elements, public array $issues) {}

    public function toArray(): array
    {
        return ['elements' => array_map(static fn (FusedGeometryElementData $item): array => $item->toArray(), $this->elements), 'issues' => $this->issues];
    }
}
