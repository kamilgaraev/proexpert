<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final class ConfirmedProjectModelProjector
{
    public function project(ProjectModelMergeResult $result): ConfirmedProjectModelProjection
    {
        return new ConfirmedProjectModelProjection($result->resolved);
    }
}
