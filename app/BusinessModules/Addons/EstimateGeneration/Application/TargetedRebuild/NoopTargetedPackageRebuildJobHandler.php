<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

final class NoopTargetedPackageRebuildJobHandler implements TargetedPackageRebuildJobHandler
{
    public function handle(string $operationId): void
    {
    }
}
