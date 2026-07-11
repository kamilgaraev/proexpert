<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final readonly class StoredBuildingModel
{
    public function __construct(
        public int $id,
        public BuildingModelOperationContext $context,
        public string $modelVersion,
        public string $contentVersion,
        public bool $created,
    ) {}
}
