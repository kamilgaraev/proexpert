<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ConfirmedProjectModelProjection
{
    public function __construct(public array $values)
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Confirmed projection values must be a list.');
        }
        foreach ($values as $value) {
            if (! $value instanceof ProjectModelResolvedValue) {
                throw new InvalidArgumentException('Confirmed projection contains an invalid value.');
            }
        }
    }
}
