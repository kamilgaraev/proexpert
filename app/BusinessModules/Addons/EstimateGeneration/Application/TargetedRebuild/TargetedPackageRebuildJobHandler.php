<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

interface TargetedPackageRebuildJobHandler
{
    public function handle(string $operationId): void;
}
