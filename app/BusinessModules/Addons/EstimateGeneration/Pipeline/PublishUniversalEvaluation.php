<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationExecutionActor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationRevision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class PublishUniversalEvaluation
{
    public function __construct(private SaveEvaluationRevision $revisions, private AdvanceEstimateGeneration $advance,
        private BuildMostEstimateDraft $artifacts = new BuildMostEstimateDraft) {}

    public function publish(CheckpointClaim $claim, array $draft): void
    {
        $context = $claim->context;
        $session = EstimateGenerationSession::query()->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)->whereKey($context->sessionId)->lockForUpdate()->firstOrFail();
        if ($session->status !== EstimateGenerationStatus::Generating || (int) $session->state_version !== $context->stateVersion
            || $context->generationAttemptId === null
            || ! hash_equals($context->generationAttemptId, (string) ($session->input_payload['generation_attempt_id'] ?? ''))) {
            throw new StaleEstimateGenerationState($context->sessionId, $context->stateVersion);
        }
        $actor = EstimateGenerationExecutionActor::resolve($session->input_payload ?? [], (int) $session->user_id);
        $inputHash = $session->input_payload['generation_evaluation_input_hash'] ?? null;
        if ($actor === null || ! is_string($inputHash) || ! $this->artifacts->verifyArtifact($draft)
            || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal'
            || ($draft['generation_contract'] ?? null) !== \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT
            || ! is_array($draft['evaluation_result'] ?? null)
            || $context->baseInputVersion === null || ! is_string($draft['source_input_version'] ?? null)
            || ! hash_equals($context->baseInputVersion, $draft['source_input_version'])
            || ! \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot::isFresh($draft, $draft['quality_summary']['review_items'] ?? [])) {
            throw new \DomainException('universal_evaluation_publication_incomplete');
        }
        $receipt = $this->revisions->save($actor, $context->organizationId, $context->projectId, $context->sessionId,
            $context->stateVersion, $context->generationAttemptId, $inputHash, $draft['evaluation_result']);
        $this->advance->generationCompleted($session, true, ['draft_payload' => $draft,
            'analysis_payload' => [...($session->analysis_payload ?? []), 'evaluation_revision' => $receipt],
            'processing_stage' => ProcessingStage::ValidateDraft->value, 'processing_progress' => 100, 'last_error' => null]);
    }
}
