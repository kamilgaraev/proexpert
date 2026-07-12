<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

final readonly class ScaleResolutionData
{
    public function __construct(public string $status, public ?float $metersPerUnit, public array $evidenceRefs, public ?string $blockingIssue) {}
}
