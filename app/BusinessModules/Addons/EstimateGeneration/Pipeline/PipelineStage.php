<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

interface PipelineStage
{
    public function stage(): ProcessingStage;

    public function execute(PipelineContext $context): PipelineStageResult;
}
