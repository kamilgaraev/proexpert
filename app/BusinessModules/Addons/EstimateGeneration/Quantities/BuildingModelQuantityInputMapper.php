<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;

interface BuildingModelQuantityInputMapper
{
    /** @return array<string, mixed> */
    public function map(NormalizedBuildingModelData $model): array;
}
