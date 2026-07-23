<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

interface TargetedPackageRebuildSessionSource
{
    public function find(TargetedPackageRebuildOperationData $operation): ?TargetedPackageRebuildSessionSnapshot;
}
