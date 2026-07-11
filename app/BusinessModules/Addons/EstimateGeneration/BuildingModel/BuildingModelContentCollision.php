<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use RuntimeException;

final class BuildingModelContentCollision extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('estimate_generation.building_model_content_collision');
    }
}
