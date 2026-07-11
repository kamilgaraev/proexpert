<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;

final readonly class ResolvePricesStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(private EstimatePricingService $pricing, private StageResultFactory $results) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::ResolvePrices;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $data = $context->priorOutputs->payload(ProcessingStage::AssembleResources);
        foreach ($data['local_estimates'] as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = $this->pricing->price($section['work_items']);
            }
        }

        return $this->results->make($context, $this->stage(), $data);
    }
}
