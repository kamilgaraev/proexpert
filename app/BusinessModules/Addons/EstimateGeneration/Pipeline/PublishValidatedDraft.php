<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationAuditService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use DateTimeImmutable;

final readonly class PublishValidatedDraft implements PipelineCompletionHook
{
    public function __construct(
        private EstimateGenerationPackagePersistenceService $packages,
        private EstimateGenerationAuditService $audit,
        private AdvanceEstimateGeneration $advance,
        private PipelineArtifactStore $artifacts,
        private FinalizationOutbox $finalizations,
    ) {}

    public function beforeComplete(CheckpointClaim $claim, PipelineStageResult $result, DateTimeImmutable $completedAt): void
    {
        if ($result->stage !== ProcessingStage::ValidateDraft) {
            return;
        }
        $data = $result->transientData ?? [];
        $draft = $data['draft'] ?? null;
        if (! is_array($draft) || ! is_bool($data['requires_review'] ?? null)) {
            throw new \DomainException('Validated draft output is incomplete.');
        }
        $session = EstimateGenerationSession::query()
            ->whereKey($claim->context->sessionId)
            ->where('organization_id', $claim->context->organizationId)
            ->where('project_id', $claim->context->projectId)
            ->lockForUpdate()
            ->first();
        if (! $session instanceof EstimateGenerationSession
            || (int) $session->state_version !== $claim->context->stateVersion
            || $session->status !== EstimateGenerationStatus::Generating
            || $claim->context->generationAttemptId === null
            || ! hash_equals($claim->context->generationAttemptId, (string) ($session->input_payload['generation_attempt_id'] ?? ''))) {
            throw new StaleEstimateGenerationState($claim->context->sessionId, $claim->context->stateVersion);
        }
        $this->packages->syncFromDraft($session, $draft);
        $this->audit->recordNormativeDecisionSummary($session, $draft);
        $this->advance->generationCompleted($session, $data['requires_review'], [
            'processing_stage' => ProcessingStage::ValidateDraft->value,
            'processing_progress' => 100,
            'draft_payload' => $draft,
            'problem_flags' => $draft['problem_flags'] ?? [],
            'last_error' => null,
        ]);
        $this->finalizations->enqueue(FinalizationEvent::completed(
            $claim->context->organizationId,
            $claim->context->projectId,
            $claim->context->sessionId,
            (string) $claim->context->generationAttemptId,
        ), $completedAt);
    }
}
