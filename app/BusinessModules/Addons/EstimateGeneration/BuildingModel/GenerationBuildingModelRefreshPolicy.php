<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final readonly class GenerationBuildingModelRefreshPolicy
{
    public function preservesLatestModel(string $scaleStatus, bool $hasActiveUserConfirmation): bool
    {
        return $scaleStatus === 'confirmed' && $hasActiveUserConfirmation;
    }
}
