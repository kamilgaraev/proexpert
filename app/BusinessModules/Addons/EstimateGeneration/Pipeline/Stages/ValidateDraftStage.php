<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateGenerationQualityGateService;

final readonly class ValidateDraftStage implements PipelineStage
{
    public function __construct(private EstimateValidationService $validation, private EstimateGenerationQualityGateService $qualityGate, private StageResultFactory $results) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::ValidateDraft;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $input = $context->priorOutputs->payload(ProcessingStage::BuildDraft);
        $draft = $this->validation->validate($input['draft']);
        $quality = $this->qualityGate->evaluate($draft);
        $draft['quality_summary'] = [...($draft['quality_summary'] ?? []), ...$quality->toArray()];
        $draft['problem_flags'] = array_values(array_unique([...($draft['problem_flags'] ?? []), ...$quality->criticalFlags, ...$quality->warningFlags]));

        return $this->results->make($context, $this->stage(), ['draft' => $draft, 'requires_review' => $quality->level !== 'passed']);
    }
}
