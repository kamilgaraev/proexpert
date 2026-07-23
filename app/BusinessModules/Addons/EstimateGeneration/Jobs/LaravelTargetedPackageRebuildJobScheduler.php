<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildJobScheduler;

final class LaravelTargetedPackageRebuildJobScheduler implements TargetedPackageRebuildJobScheduler
{
    public function schedule(string $operationId): void
    {
        RunTargetedPackageRebuildJob::dispatch($operationId)
            ->onConnection(RunTargetedPackageRebuildJob::CONNECTION)
            ->onQueue(RunTargetedPackageRebuildJob::QUEUE)
            ->afterCommit();
    }
}
