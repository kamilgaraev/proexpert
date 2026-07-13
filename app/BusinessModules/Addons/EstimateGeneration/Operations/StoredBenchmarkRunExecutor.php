<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

interface StoredBenchmarkRunExecutor
{
    public function execute(
        int $runId,
        string $datasetType,
        string $adapterId,
        string $promptVersion,
        ?string $manifestLocator,
    ): void;
}
