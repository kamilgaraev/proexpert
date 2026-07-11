<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;

class DraftPipelineEntrypoint
{
    public function __construct(private readonly PipelineRunner $runner, private readonly PipelineOutputRepository $outputs) {}

    public function run(FailureExecutionSnapshot $snapshot): DraftPipelineRunResult
    {
        $context = new PipelineContext(
            sessionId: $snapshot->sessionId,
            organizationId: $snapshot->organizationId,
            projectId: $snapshot->projectId,
            stateVersion: $snapshot->stateVersion,
            sessionStatus: $snapshot->status,
            inputVersion: $snapshot->attemptId,
        );
        $result = $this->runner->runNext($context->withPriorOutputs($this->outputs->priorOutputs($context)));

        $session = EstimateGenerationSession::query()->find($snapshot->sessionId);
        $executedStage = $result?->stage;

        return new DraftPipelineRunResult(
            session: $session,
            executedStage: $executedStage,
            dispatchNext: $executedStage !== null && $executedStage !== ProcessingStage::ValidateDraft,
            finalized: $executedStage === ProcessingStage::ValidateDraft,
        );
    }
}
