<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;

final class LaravelEstimateGenerationUnitJobDispatcher implements EstimateGenerationUnitJobDispatcher
{
    public function dispatch(int $unitId, string $sourceVersion, bool $priority = false): void
    {
        ProcessEstimateGenerationUnitJob::dispatch($unitId, $sourceVersion)
            ->onConnection(ProcessEstimateGenerationUnitJob::CONNECTION)
            ->onQueue($priority ? ProcessEstimateGenerationUnitJob::RECOVERY_QUEUE : ProcessEstimateGenerationUnitJob::QUEUE)
            ->afterCommit();
    }
}
