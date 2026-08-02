<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ConfirmedProjectModelProjection
{
    public function __construct(public ProjectModelResolvedValueList $values)
    {
        foreach ($values as $value) {
            if (! $value->hasConfirmedCanonicalProof()) {
                throw new InvalidArgumentException('Confirmed projection contains an unproven value.');
            }
        }
    }
}
