<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;

final class AdvanceEstimateGeneration
{
    private ?AiEstimateQuotaService $aiEstimateQuota;

    public function __construct(
        private EstimateGenerationWorkflow $workflow,
        ?AiEstimateQuotaService $aiEstimateQuota = null,
    ) {
        $this->aiEstimateQuota = $aiEstimateQuota;
    }

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
            'input_payload' => $this->withoutPlanningReview($session),
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
            'input_payload' => $this->withoutPlanningReview($session),
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $inputPayloadChanges */
    public function generationStarted(
        EstimateGenerationSession $session,
        string $attemptId,
        array $inputPayloadChanges = [],
    ): EstimateGenerationSession {
        if ($session->status === EstimateGenerationStatus::Generating) {
            return $session;
        }

        return $this->withQuotaForGeneration($session, fn (EstimateGenerationSession $lockedSession): EstimateGenerationSession => $this->transitionToGeneration($lockedSession, $attemptId, $inputPayloadChanges),
        );
    }

    /** @param array<string, mixed> $inputPayloadChanges */
    public function generationRetried(
        EstimateGenerationSession $session,
        string $attemptId,
        array $inputPayloadChanges = [],
    ): EstimateGenerationSession {
        return $this->withQuotaForGeneration($session, function (EstimateGenerationSession $lockedSession) use ($attemptId, $inputPayloadChanges): EstimateGenerationSession {
            return $this->workflow->transition($lockedSession, EstimateGenerationEvent::Retried, $this->generationAttributes(
                $lockedSession,
                $attemptId,
                $inputPayloadChanges,
            ));
        });
    }

    /** @param array<string, mixed> $inputPayloadChanges */
    public function generationRestarted(
        EstimateGenerationSession $session,
        string $attemptId,
        array $inputPayloadChanges = [],
    ): EstimateGenerationSession {
        return $this->withQuotaForGeneration($session, function (EstimateGenerationSession $lockedSession) use ($attemptId, $inputPayloadChanges): EstimateGenerationSession {
            return $this->workflow->update($lockedSession, [EstimateGenerationStatus::Generating], $this->generationAttributes(
                $lockedSession,
                $attemptId,
                $inputPayloadChanges,
            ));
        });
    }

    private function transitionToGeneration(
        EstimateGenerationSession $session,
        string $attemptId,
        array $inputPayloadChanges,
    ): EstimateGenerationSession {
        $attributes = $this->generationAttributes($session, $attemptId, $inputPayloadChanges);
        $inputPayload = $attributes['input_payload'];

        if ($session->status === EstimateGenerationStatus::Applied) {
            $supersededEstimateIds = array_values(array_unique(array_filter(array_map(
                static fn (mixed $estimateId): int => (int) $estimateId,
                is_array($inputPayload['superseded_estimate_ids'] ?? null)
                    ? $inputPayload['superseded_estimate_ids']
                    : [],
            ))));
            if ($session->applied_estimate_id !== null) {
                $supersededEstimateIds[] = (int) $session->applied_estimate_id;
            }
            $attributes['applied_estimate_id'] = null;
            $attributes['applied_at'] = null;
            $attributes['input_payload']['superseded_estimate_ids'] = array_values(array_unique($supersededEstimateIds));
        }

        return $this->workflow->transition($session, EstimateGenerationEvent::GenerationStarted, $attributes);
    }

    /** @param array<string, mixed> $inputPayloadChanges */
    /** @return array<string, mixed> */
    private function generationAttributes(EstimateGenerationSession $session, string $attemptId, array $inputPayloadChanges): array
    {
        return [
            'processing_stage' => 'generating',
            'processing_progress' => 40,
            'last_error' => null,
            'failure_code' => null,
            'input_payload' => [
                ...($session->input_payload ?? []),
                ...$inputPayloadChanges,
                'generation_attempt_id' => $attemptId,
                'generation_requested' => false,
            ],
        ];
    }

    /** @param callable(EstimateGenerationSession): EstimateGenerationSession $transition */
    private function withQuotaForGeneration(EstimateGenerationSession $session, callable $transition): EstimateGenerationSession
    {
        if ($this->aiEstimateQuota === null) {
            return $transition($session);
        }

        return $this->aiEstimateQuota->reserveSessionWithTransition($session, $transition(...));
    }

    public function documentsNeedReview(
        EstimateGenerationSession $session,
        ?string $failureCode = null,
        array $planningLimitations = [],
    ): EstimateGenerationSession {
        $attributes = [
            'processing_stage' => 'input_review_required',
            'processing_progress' => 35,
            'last_error' => null,
            'failure_code' => $failureCode,
        ];
        if ($planningLimitations !== []) {
            $limitations = array_values(array_unique(array_filter(
                $planningLimitations,
                static fn (mixed $limitation): bool => is_string($limitation) && trim($limitation) !== '',
            )));
            if ($limitations === [] || count($limitations) > 20) {
                throw new \InvalidArgumentException('Planning review limitations are invalid.');
            }
            $attributes['input_payload'] = [
                ...($session->input_payload ?? []),
                'planning_review' => [
                    'status' => 'blocked',
                    'limitations' => $limitations,
                ],
            ];
        }

        return $this->workflow->transition($session, EstimateGenerationEvent::DocumentsNeedReview, [
            ...$attributes,
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
        $resumeRequestedGeneration = $this->hasBlockedPlanningReview($session)
            && ($session->input_payload['generation_requested'] ?? false) === true;
        if ($session->status === EstimateGenerationStatus::Failed
            && $session->resume_status === EstimateGenerationStatus::ProcessingDocuments) {
            return $this->workflow->transition($session, EstimateGenerationEvent::Retried, [
                'processing_stage' => 'processing_documents',
                'processing_progress' => 5,
                'last_error' => null,
                'failure_code' => null,
                'input_payload' => $this->withoutPlanningReview($session),
            ]);
        }
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
                ...$this->withoutPlanningReview($session),
                'generation_attempt_id' => null,
                'generation_requested' => $resumeRequestedGeneration,
            ],
        ]);
    }

    private function hasBlockedPlanningReview(EstimateGenerationSession $session): bool
    {
        $review = is_array($session->input_payload['planning_review'] ?? null)
            ? $session->input_payload['planning_review']
            : [];

        return ($review['status'] ?? null) === 'blocked';
    }

    private function withoutPlanningReview(EstimateGenerationSession $session): array
    {
        $payload = is_array($session->input_payload) ? $session->input_payload : [];
        unset($payload['planning_review']);

        return $payload;
    }
}
