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
    ) {}

    public function next(FailureExecutionSnapshot $snapshot): ?PipelineContext
    {
        $session = EstimateGenerationSession::query()
            ->whereKey($snapshot->sessionId)
            ->where('organization_id', $snapshot->organizationId)
            ->where('project_id', $snapshot->projectId)
            ->with([
                'documents' => static fn ($query) => $query->orderBy('id'),
                'documents.facts' => static fn ($query) => $query
                    ->where('organization_id', $snapshot->organizationId)->where('project_id', $snapshot->projectId)->orderBy('id'),
                'documents.drawingElements' => static fn ($query) => $query
                    ->where('organization_id', $snapshot->organizationId)->where('project_id', $snapshot->projectId)->orderBy('id'),
                'documents.quantityTakeoffs' => static fn ($query) => $query
                    ->where('organization_id', $snapshot->organizationId)->where('project_id', $snapshot->projectId)->orderBy('id'),
                'documents.scopeInferences' => static fn ($query) => $query
                    ->where('organization_id', $snapshot->organizationId)->where('project_id', $snapshot->projectId)->orderBy('id'),
            ])
            ->first();
        if (! $session instanceof EstimateGenerationSession
            || (int) $session->state_version !== $snapshot->stateVersion
            || $session->status->value !== $snapshot->status
            || ! hash_equals($snapshot->attemptId, (string) ($session->input_payload['generation_attempt_id'] ?? ''))) {
            throw new StaleEstimateGenerationState($snapshot->sessionId, $snapshot->stateVersion);
        }
        $baseInputVersion = PipelineBaseInputVersion::fromSession($session);
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
