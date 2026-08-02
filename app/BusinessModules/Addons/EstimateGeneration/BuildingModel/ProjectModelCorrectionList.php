<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final class ProjectModelCorrectionList extends ProjectModelTypedList
{
    public static function of(ProjectModelCorrection ...$items): self { return new self($items, ProjectModelCorrection::class); }
}
