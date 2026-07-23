<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;

final readonly class EloquentPipelineExecutionPlanner implements PipelineExecutionPlanner
{
    public function __construct(
        private PipelineOutputRepository $outputs,
        private PipelinePlanResolver $resolver,
        private EvidenceAwarePipelineBaseInputVersionResolver $baseInputVersions,
    ) {}

    public function next(FailureExecutionSnapshot $snapshot): ?PipelineContext
    {
        $session = EstimateGenerationSession::query()
            ->whereKey($snapshot->sessionId)
            ->where('organization_id', $snapshot->organizationId)
            ->where('project_id', $snapshot->projectId)
            ->select(['id', 'organization_id', 'project_id', 'status', 'state_version', 'input_payload'])
            ->first();
        if (! $session instanceof EstimateGenerationSession
            || (int) $session->state_version !== $snapshot->stateVersion
            || $session->status->value !== $snapshot->status
            || ! hash_equals($snapshot->attemptId, (string) ($session->input_payload['generation_attempt_id'] ?? ''))) {
            throw new StaleEstimateGenerationState($snapshot->sessionId, $snapshot->stateVersion);
        }

        $baseInputVersion = $this->baseInputVersions->fromSession($session);
        $seed = new PipelineContext(
            sessionId: $snapshot->sessionId,
            organizationId: $snapshot->organizationId,
            projectId: $snapshot->projectId,
            stateVersion: $snapshot->stateVersion,
            inputVersion: $baseInputVersion,
            sessionStatus: $snapshot->status,
            generationAttemptId: $snapshot->attemptId,
            baseInputVersion: $baseInputVersion,
        );

        return $this->resolver->next($seed, $this->outputs->priorOutputs($seed));
    }
}
