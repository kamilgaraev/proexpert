<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;

interface PipelineExecutionPlanner
{
    public function next(FailureExecutionSnapshot $snapshot): ?PipelineContext;
}
