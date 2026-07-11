<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class AdvanceEstimateGeneration
{
    public function __construct(private EstimateGenerationWorkflow $workflow) {}

    /** @param array<string, mixed> $attributes */
    public function documentsStarted(EstimateGenerationSession $session, array $attributes = []): EstimateGenerationSession
    {
        if ($session->status === EstimateGenerationStatus::ProcessingDocuments) {
            return $attributes === [] ? $session : $this->workflow->update($session, $attributes);
        }
        if ($session->status !== EstimateGenerationStatus::Draft) {
            return $session;
        }

        return $this->workflow->transition($session, EstimateGenerationEvent::StartDocumentProcessing, [
            'processing_stage' => 'processing_documents',
            'processing_progress' => 5,
            'last_error' => null,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function documentsReady(EstimateGenerationSession $session, array $attributes = []): EstimateGenerationSession
    {
        $session = $this->documentsStarted($session);
        if ($session->status !== EstimateGenerationStatus::ProcessingDocuments) {
            return $attributes === [] ? $session : $this->workflow->update($session, $attributes);
        }

        return $this->workflow->transition($session, EstimateGenerationEvent::DocumentsReady, [
            'processing_stage' => 'ready_to_generate',
            'processing_progress' => 35,
            'last_error' => null,
            ...$attributes,
        ]);
    }

    public function generationStarted(EstimateGenerationSession $session, string $attemptId): EstimateGenerationSession
    {
        if ($session->status === EstimateGenerationStatus::Generating) {
            return $session;
        }

        return $this->workflow->transition($session, EstimateGenerationEvent::GenerationStarted, [
            'processing_stage' => 'generating',
            'processing_progress' => 40,
            'last_error' => null,
            'input_payload' => [
                ...($session->input_payload ?? []),
                'generation_attempt_id' => $attemptId,
                'generation_requested' => false,
            ],
        ]);
    }

    public function documentsNeedReview(EstimateGenerationSession $session): EstimateGenerationSession
    {
        return $this->workflow->transition($session, EstimateGenerationEvent::DocumentsNeedReview, [
            'processing_stage' => 'input_review_required',
            'processing_progress' => 100,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function generationCompleted(EstimateGenerationSession $session, bool $requiresReview, array $attributes): EstimateGenerationSession
    {
        return $this->workflow->transition(
            $session,
            $requiresReview ? EstimateGenerationEvent::GenerationNeedsReview : EstimateGenerationEvent::GenerationReady,
            $attributes,
        );
    }

    /** @param array<string, mixed> $attributes */
    public function reviewUpdated(EstimateGenerationSession $session, bool $requiresReview, array $attributes): EstimateGenerationSession
    {
        $event = match (true) {
            $session->status === EstimateGenerationStatus::EstimateReviewRequired && ! $requiresReview => EstimateGenerationEvent::GenerationReady,
            $session->status === EstimateGenerationStatus::EstimateReviewRequired => EstimateGenerationEvent::ReviewUpdated,
            $session->status === EstimateGenerationStatus::ReadyToApply && $requiresReview => EstimateGenerationEvent::ReviewReopened,
            default => null,
        };

        return $event === null
            ? $this->workflow->update($session, $attributes)
            : $this->workflow->transition($session, $event, $attributes);
    }

    public function failed(EstimateGenerationSession $session, \Throwable $exception): EstimateGenerationSession
    {
        if ($session->status->isTerminal() || $session->status === EstimateGenerationStatus::Failed) {
            return $session;
        }

        return $this->workflow->transition($session, EstimateGenerationEvent::Failed, [
            'processing_stage' => 'failed',
            'processing_progress' => 0,
            'last_error' => mb_substr($exception->getMessage(), 0, 500),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(EstimateGenerationSession $session, array $attributes): EstimateGenerationSession
    {
        return $this->workflow->update($session, $attributes);
    }

    public function documentsChanged(EstimateGenerationSession $session): EstimateGenerationSession
    {
        if ($session->status === EstimateGenerationStatus::Draft
            || $session->status === EstimateGenerationStatus::ProcessingDocuments) {
            return $this->documentsStarted($session);
        }

        return $this->workflow->transition($session, EstimateGenerationEvent::DocumentsChanged, [
            'processing_stage' => 'processing_documents',
            'processing_progress' => 5,
            'last_error' => null,
        ]);
    }
}
