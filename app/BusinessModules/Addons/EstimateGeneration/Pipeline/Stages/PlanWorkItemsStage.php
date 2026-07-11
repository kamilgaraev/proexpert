<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;

final readonly class PlanWorkItemsStage implements PipelineStage
{
    public function __construct(
        private PackagePlannerService $packagePlanner,
        private EstimateDecompositionService $decomposition,
        private NormativeWorkItemPlannerService $workItemPlanner,
        private StageResultFactory $results,
    ) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::PlanWorkItems;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $analysis = $context->priorOutputs->payload(ProcessingStage::ExtractQuantities)['analysis'];
        $profile = $this->packagePlanner->profileFromAnalysis($analysis);
        $plan = $this->packagePlanner->plan($profile);
        $localEstimates = $this->decomposition->decomposePackagePlan($analysis, $plan);
        foreach ($localEstimates as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $localEstimates[$localIndex]['sections'][$sectionIndex]['work_items'] = $this->workItemPlanner->build($localEstimate, $section, $analysis);
            }
        }

        return $this->results->make($context, $this->stage(), [
            'analysis' => $analysis,
            'object_profile' => $profile->toArray(),
            'package_plan' => $plan->toArray(),
            'document_requirements' => $this->packagePlanner->documentRequirements($profile),
            'generation_mode' => EstimateGenerationMode::fromInput($profile->planningSignals['generation_mode'] ?? null)->value,
            'local_estimates' => $localEstimates,
        ], ['local_estimates_count' => count($localEstimates)]);
    }
}
