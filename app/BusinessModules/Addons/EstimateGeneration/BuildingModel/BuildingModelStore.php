<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;

interface BuildingModelStore
{
    public function transaction(BuildingModelOperationContext $context, callable $callback): mixed;

    public function insertOrGet(BuildingModelOperationContext $context, NormalizedBuildingModelData $model): StoredBuildingModel;

    public function attachEvidence(StoredBuildingModel $stored, array $evidenceIds): void;
}
