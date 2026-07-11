<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;

class DraftPipelineEntrypoint
{
    public function __construct(private readonly PipelineRunner $runner) {}

    public function run(FailureExecutionSnapshot $snapshot): ?EstimateGenerationSession
    {
        $this->runner->runNext(new PipelineContext(
            sessionId: $snapshot->sessionId,
            organizationId: $snapshot->organizationId,
            projectId: $snapshot->projectId,
            stateVersion: $snapshot->stateVersion,
            sessionStatus: $snapshot->status,
            inputVersion: $snapshot->attemptId,
        ));

        return EstimateGenerationSession::query()->find($snapshot->sessionId);
    }
}
