<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

interface PipelineOutputRepository
{
    public function priorOutputs(PipelineContext $context): PipelinePriorOutputs;
}
