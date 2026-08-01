<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final class ProjectModelResolvedValueList extends ProjectModelTypedList
{
    public static function of(ProjectModelResolvedValue ...$items): self { return new self($items, ProjectModelResolvedValue::class); }
}
