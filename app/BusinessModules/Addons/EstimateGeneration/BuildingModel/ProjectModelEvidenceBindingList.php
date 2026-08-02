<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final class ProjectModelEvidenceBindingList extends ProjectModelTypedList
{
    public static function of(ProjectModelEvidenceBinding ...$items): self { return new self($items, ProjectModelEvidenceBinding::class); }
}
