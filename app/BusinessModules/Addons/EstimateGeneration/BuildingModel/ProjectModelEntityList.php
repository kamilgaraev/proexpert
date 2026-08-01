<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final class ProjectModelEntityList extends ProjectModelTypedList
{
    public static function of(ProjectModelEntity ...$items): self { return new self($items, ProjectModelEntity::class); }
}
