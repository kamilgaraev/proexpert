<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelMergeResult
{
    private function __construct(
        public ProjectModelResolvedValueList $resolved,
        public ProjectModelConflictList $conflicts,
        public ProjectModelConflictList $unconfirmed,
    ) {
        foreach ($resolved as $value) {
            if (! $value->hasConfirmedCanonicalProof()) {
                throw new InvalidArgumentException('Resolved value has no confirmed canonical proof.');
            }
        }
        foreach ($conflicts as $conflict) {
            if (! str_ends_with($conflict->code, '_conflict')) {
                throw new InvalidArgumentException('Conflict result contains an unconfirmed value.');
            }
        }
        foreach ($unconfirmed as $conflict) {
            if (! str_ends_with($conflict->code, '_unconfirmed')) {
                throw new InvalidArgumentException('Unconfirmed result contains a conflict.');
            }
        }
    }

    public static function fromResolution(ProjectModelResolvedValueList $resolved, ProjectModelConflictList $conflicts, ProjectModelConflictList $unconfirmed): self
    {
        return new self($resolved, $conflicts, $unconfirmed);
    }
}
