<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;

final readonly class UnderstandDocumentsStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(private GenerationPipelineDataGateway $gateway, private StageResultFactory $results) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::UnderstandDocuments;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $source = $this->gateway->manifest($context);

        return $this->results->make($context, $this->stage(), $source, ['documents_count' => $source['documents_count']]);
    }
}
