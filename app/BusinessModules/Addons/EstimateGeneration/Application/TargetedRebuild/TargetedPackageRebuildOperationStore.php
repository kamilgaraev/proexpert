<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

interface TargetedPackageRebuildOperationStore
{
    public function createOrFind(TargetedPackageRebuildOperationData $operation): TargetedPackageRebuildOperationStoreResult;
}
