<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint;

final readonly class RunEstimateGenerationDraft
{
    public function __construct(private DraftPipelineEntrypoint $pipeline) {}

    public function handle(
        FailureExecutionSnapshot $snapshot,
        int $expectedStateVersion,
        string $attemptId,
    ): void {
        if ($expectedStateVersion !== $snapshot->stateVersion
            || ! hash_equals($attemptId, (string) $snapshot->attemptId)) {
            return;
        }

        $current = EstimateGenerationSession::query()->find($snapshot->sessionId);
        if (! $current instanceof EstimateGenerationSession
            || (int) $current->state_version !== $expectedStateVersion
            || $current->status->value !== $snapshot->status
            || ! hash_equals($attemptId, (string) ($current->input_payload['generation_attempt_id'] ?? ''))) {
            return;
        }

        try {
            $run = $this->pipeline->run($snapshot);
        } catch (StaleEstimateGenerationState) {
            return;
        }

        if (! $run->dispatchNext) {
            return;
        }

        GenerateEstimateDraftJob::dispatch(
            $snapshot->sessionId,
            $expectedStateVersion,
            $attemptId,
            $snapshot->nextEvent(),
        )->onQueue(GenerateEstimateDraftJob::QUEUE)->afterCommit();
    }
}
