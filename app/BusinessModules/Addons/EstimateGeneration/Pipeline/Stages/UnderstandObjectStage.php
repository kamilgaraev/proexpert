<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;

final readonly class UnderstandObjectStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(private ConstructionSemanticParser $parser, private GenerationPipelineDataGateway $gateway, private StageResultFactory $results) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::UnderstandObject;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $context->priorOutputs->payload(ProcessingStage::UnderstandDocuments);
        $source = $this->gateway->source($context);
        $analysis = $this->parser->parse($source['input'], $source['documents']);

        return $this->results->make($context, $this->stage(), ['analysis' => $analysis]);
    }
}
