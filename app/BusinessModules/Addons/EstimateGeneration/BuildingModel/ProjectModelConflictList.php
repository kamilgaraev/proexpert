<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final class ProjectModelConflictList extends ProjectModelTypedList
{
    public static function of(ProjectModelConflict ...$items): self { return new self($items, ProjectModelConflict::class); }
}
