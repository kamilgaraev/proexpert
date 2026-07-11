<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;

final readonly class AssembleResourcesStage implements PipelineStage
{
    public function __construct(private AssembleMatchedResources $assembler, private StageResultFactory $results) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::AssembleResources;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $data = $context->priorOutputs->payload(ProcessingStage::MatchNormatives);
        $assembled = $this->assembler->handle($data);

        return $this->results->make($context, $this->stage(), $assembled['data'], ['resources_count' => $assembled['resources_count']]);
    }
}
