<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;

final readonly class StageResultFactory
{
    public function __construct(private PipelineArtifactStore $artifacts) {}

    public function make(PipelineContext $context, ProcessingStage $stage, array $data, array $metrics = [], array $warnings = []): PipelineStageResult
    {
        $output = $this->artifacts->write($context, $stage, $data);

        return new PipelineStageResult($stage, $output->version, $metrics, $warnings, $output, $data);
    }
}
