<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final class ProjectModelAssertionList extends ProjectModelTypedList
{
    public static function of(ProjectModelAssertion ...$items): self { return new self($items, ProjectModelAssertion::class); }
}
