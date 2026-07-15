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
            return $attributes === [] ? $session : $this->workflow->update(
                $session,
                [EstimateGenerationStatus::ProcessingDocuments],
                $attributes,
            );
        }
        if ($session->status !== EstimateGenerationStatus::Draft) {
            return $session;
        }

        return $this->workflow->transition($session, EstimateGenerationEvent::StartDocumentProcessing, [
            'processing_stage' => 'processing_documents',
            'processing_progress' => 5,
            'last_error' => null,
            'failure_code' => null,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function documentsReady(EstimateGenerationSession $session, array $attributes = []): EstimateGenerationSession
    {
        $session = $this->documentsStarted($session);
        if ($session->status !== EstimateGenerationStatus::ProcessingDocuments) {
            return $attributes === [] ? $session : $this->workflow->update(
                $session,
                [EstimateGenerationStatus::Generating],
                $attributes,
            );
        }

        return $this->workflow->transition($session, EstimateGenerationEvent::DocumentsReady, [
            'processing_stage' => 'ready_to_generate',
            'processing_progress' => 35,
            'last_error' => null,
            'failure_code' => null,
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
            'failure_code' => null,
            'input_payload' => [
                ...($session->input_payload ?? []),
                'generation_attempt_id' => $attemptId,
                'generation_requested' => false,
            ],
        ]);
    }

    public function documentsNeedReview(EstimateGenerationSession $session, ?string $failureCode = null): EstimateGenerationSession
    {
        return $this->workflow->transition($session, EstimateGenerationEvent::DocumentsNeedReview, [
            'processing_stage' => 'input_review_required',
            'processing_progress' => 35,
            'last_error' => null,
            'failure_code' => $failureCode,
        ]);
    }

    public function generationNeedsReview(EstimateGenerationSession $session, string $failureCode): EstimateGenerationSession
    {
        return $this->workflow->transition($session, EstimateGenerationEvent::GenerationNeedsReview, [
            'processing_stage' => 'estimate_review_required',
            'processing_progress' => 100,
            'last_error' => null,
            'failure_code' => $failureCode,
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
            ? $this->workflow->update(
                $session,
                [EstimateGenerationStatus::EstimateReviewRequired, EstimateGenerationStatus::ReadyToApply],
                $attributes,
            )
            : $this->workflow->transition($session, $event, $attributes);
    }

    public function failed(EstimateGenerationSession $session, string $failureCode): EstimateGenerationSession
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $failureCode) !== 1) {
            throw new \InvalidArgumentException('Invalid estimate generation failure code.');
        }
        if ($session->status->isTerminal() || $session->status === EstimateGenerationStatus::Failed) {
            return $session;
        }

        return $this->workflow->transition($session, EstimateGenerationEvent::Failed, [
            'processing_stage' => 'failed',
            'processing_progress' => 0,
            'last_error' => null,
            'failure_code' => $failureCode,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    /** @param list<EstimateGenerationStatus> $allowedStatuses */
    public function update(
        EstimateGenerationSession $session,
        array $allowedStatuses,
        array $attributes,
    ): EstimateGenerationSession {
        return $this->workflow->update($session, $allowedStatuses, $attributes);
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
            'failure_code' => null,
            'input_payload' => [
                ...($session->input_payload ?? []),
                'generation_attempt_id' => null,
                'generation_requested' => false,
            ],
        ]);
    }
}
