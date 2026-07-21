<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ShadowArbiterCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector;

final readonly class ValidateDraftStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(
        private EstimateValidationService $validation,
        private DraftReadinessProjector $readiness,
        private StageResultFactory $results,
        private ?ShadowArbiterCoordinator $arbiter = null,
    ) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::ValidateDraft;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $input = $context->priorOutputs->payload(ProcessingStage::BuildDraft);
        $draft = $this->validation->validate($input['draft']);
        $draft = $this->readiness->project($draft);
        if ($this->arbiter !== null && $context->claimToken !== null) {
            $draft = $this->arbiter->review($draft, new ArbiterOperationContext(
                $context->organizationId,
                $context->projectId,
                $context->sessionId,
                $context->claimToken,
                $context->inputVersion,
                $context->stageAttempt ?? 1,
            ));
        }
        $blockingCodes = array_column((array) ($draft['readiness_summary']['blocking_issues'] ?? []), 'code');

        return $this->results->make($context, $this->stage(), ['draft' => $draft, 'requires_review' => $blockingCodes !== []]);
    }
}
