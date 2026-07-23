<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

interface TargetedPackageRebuildJobScheduler
{
    public function schedule(string $operationId): void;
}
