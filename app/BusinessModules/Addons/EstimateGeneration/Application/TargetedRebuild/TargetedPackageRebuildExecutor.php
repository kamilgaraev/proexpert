<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

interface TargetedPackageRebuildExecutor
{
    public function rebuild(TargetedPackageRebuildCommand $command): TargetedPackagePatchResult;
}
