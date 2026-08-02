<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final class ConfirmedProjectModelProjector
{
    public function project(ProjectModelMergeResult $result): ConfirmedProjectModelProjection
    {
        $confirmed = [];
        foreach ($result->resolved as $value) {
            if ($value->hasConfirmedCanonicalProof()) {
                $confirmed[] = $value;
            }
        }

        return new ConfirmedProjectModelProjection(ProjectModelResolvedValueList::of(...$confirmed));
    }
}
