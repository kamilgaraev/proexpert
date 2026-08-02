<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final class ProjectModelCandidateList extends ProjectModelTypedList
{
    public static function of(ProjectModelCandidate ...$items): self { return new self($items, ProjectModelCandidate::class); }
}
