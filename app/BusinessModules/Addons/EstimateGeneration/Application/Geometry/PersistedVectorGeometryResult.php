<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;

final readonly class PersistedVectorGeometryResult
{
    public function __construct(
        public NormalizedBuildingModelData $model,
        public GeometryReviewedSource $sourceConfirmationContext,
        /** @var array<string, mixed> */
        public array $sourceConfirmation,
    ) {}
}
