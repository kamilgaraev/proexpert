<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;

class DraftPipelineEntrypoint
{
    public function __construct(private readonly PipelineRunner $runner, private readonly PipelineExecutionPlanner $planner) {}

    public function run(FailureExecutionSnapshot $snapshot): DraftPipelineRunResult
    {
        $context = $this->planner->next($snapshot);
        $result = $context !== null ? $this->runner->runNext($context) : null;

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
