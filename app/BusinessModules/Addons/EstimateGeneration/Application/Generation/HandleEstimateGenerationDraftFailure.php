<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class HandleEstimateGenerationDraftFailure
{
    public function __construct(
        private FailureRecorder $failures,
        private FailureWorkflowHandler $workflow,
        private PipelineCheckpointStore $checkpoints,
        private PipelineDefinitionGraph $definitions,
    ) {}

    public function handle(FailureExecutionSnapshot $snapshot, Throwable $error): void
    {
        $checkpoint = EstimateGenerationPipelineCheckpoint::query()
            ->where('session_id', $snapshot->sessionId)
            ->where('organization_id', $snapshot->organizationId)
            ->where('project_id', $snapshot->projectId)
            ->where('generation_attempt_id', $snapshot->attemptId)
            ->where('status', 'running')
            ->whereNotNull('claim_token')
            ->orderByDesc('id')
            ->first();

        if ($checkpoint instanceof EstimateGenerationPipelineCheckpoint) {
            try {
                $failure = $this->failures->capture($error, new FailureContext(
                    organizationId: $snapshot->organizationId,
                    projectId: $snapshot->projectId,
                    sessionId: $snapshot->sessionId,
                    stage: $checkpoint->stage,
                    operation: 'run_stage',
                    attempt: (int) $checkpoint->attempt_count,
                    correlationId: AiOperationContext::deterministicId(sprintf(
                        'pipeline|%d|%s|%s',
                        $snapshot->sessionId,
                        $checkpoint->stage->value,
                        (string) $checkpoint->input_version,
                    )),
                    eventId: (string) $checkpoint->claim_token,
                    expectedSessionStateVersion: $snapshot->stateVersion,
                    expectedSessionStatus: $snapshot->status,
                    checkpointId: (int) $checkpoint->getKey(),
                ));
                $definition = $this->definitions->get($checkpoint->stage);
                $storedDependencies = is_array($checkpoint->dependency_versions) ? $checkpoint->dependency_versions : [];
                $dependencies = [];
                foreach ($definition->dependencies as $dependency) {
                    $dependencies[$dependency->value] = (string) ($storedDependencies[$dependency->value] ?? '');
                }
                $context = new PipelineContext(
                    sessionId: $snapshot->sessionId,
                    organizationId: $snapshot->organizationId,
                    projectId: $snapshot->projectId,
                    stateVersion: $snapshot->stateVersion,
                    inputVersion: (string) $checkpoint->input_version,
                    sessionStatus: $snapshot->status,
                    generationAttemptId: $snapshot->attemptId,
                    baseInputVersion: (string) $checkpoint->base_input_version,
                    stage: $checkpoint->stage,
                    dependencyVersions: $dependencies,
                );
                $claim = CheckpointClaim::acquired(
                    $context,
                    $checkpoint->stage,
                    (string) $checkpoint->claim_token,
                    (int) $checkpoint->attempt_count,
                    (int) $checkpoint->getKey(),
                );
                if ($this->checkpoints->fail($claim, $error, new DateTimeImmutable)) {
                    $this->workflow->handle($failure, $snapshot->stateVersion);
                }
            } catch (Throwable) {
            }
        }

        Log::error('[EstimateGeneration] Draft generation job failed', [
            'session_id' => $snapshot->sessionId,
            'failure_code' => 'draft_generation_failed',
            'failure_fingerprint' => hash('sha256', $error::class.'|'.(string) $error->getCode()),
        ]);
    }
}
