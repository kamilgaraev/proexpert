<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;

final readonly class UnderstandObjectStage implements PipelineStage
{
    public function __construct(private ConstructionSemanticParser $parser, private StageResultFactory $results) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::UnderstandObject;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $source = $context->priorOutputs->payload(ProcessingStage::UnderstandDocuments);
        $analysis = $this->parser->parse($source['input'], $source['documents']);

        return $this->results->make($context, $this->stage(), ['analysis' => $analysis]);
    }
}
