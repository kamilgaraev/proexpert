<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessInspector;

final readonly class ValidateDraftStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(private EstimateValidationService $validation, private DraftReadinessInspector $readiness, private StageResultFactory $results) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::ValidateDraft;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $input = $context->priorOutputs->payload(ProcessingStage::BuildDraft);
        $draft = $this->validation->validate($input['draft']);
        $inspection = $this->readiness->inspect($draft);
        $blockingCodes = array_column($inspection->blockingIssues, 'code');
        $warningCodes = array_column($inspection->warnings, 'code');
        $draft['readiness_summary'] = $inspection->toArray();
        $draft['quality_summary'] = [
            ...($draft['quality_summary'] ?? []),
            'status' => $blockingCodes === [] ? 'passed' : 'review_required',
            'level' => $blockingCodes === [] ? 'passed' : 'critical',
            'critical_flags' => $blockingCodes,
            'warning_flags' => $warningCodes,
        ];
        $draft['problem_flags'] = array_values(array_unique([...($draft['problem_flags'] ?? []), ...$blockingCodes, ...$warningCodes]));

        return $this->results->make($context, $this->stage(), ['draft' => $draft, 'requires_review' => $blockingCodes !== []]);
    }
}
