<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Closure;
use Illuminate\Support\Str;

final class RetryEstimateGenerationSession
{
    private Closure $attemptIdFactory;

    public function __construct(
        private RetryableEstimateGenerationSessionRepository $repository,
        private EstimateGenerationWorkflow $workflow,
        private EstimateGenerationRetryDispatcher $dispatcher,
        ?Closure $attemptIdFactory = null,
    ) {
        $this->attemptIdFactory = $attemptIdFactory ?? static fn (): string => (string) Str::uuid();
    }

    public function handle(RetryEstimateGenerationSessionCommand $command): EstimateGenerationSession
    {
        return $this->repository->withLockedSession(
            $command->sessionId,
            $command->organizationId,
            $command->projectId,
            function (EstimateGenerationSession $session) use ($command): EstimateGenerationSession {
                if ($session->state_version !== $command->expectedStateVersion) {
                    throw new StaleEstimateGenerationState((int) $session->getKey(), $command->expectedStateVersion);
                }
                if ($session->status === EstimateGenerationStatus::InputReviewRequired) {
                    return $this->retryInputReview($session);
                }
                if ($session->status === EstimateGenerationStatus::Generating) {
                    return $this->restartGeneration($session);
                }
                if ($session->status === EstimateGenerationStatus::ReadyToGenerate) {
                    return $this->startGeneration($session);
                }
                if ($session->status !== EstimateGenerationStatus::Failed) {
                    throw new InvalidEstimateGenerationState($session->status, 'retry');
                }

                return match ($session->resume_status) {
                    EstimateGenerationStatus::ProcessingDocuments => $this->retryDocuments($session),
                    EstimateGenerationStatus::Generating => $this->retryGeneration($session),
                    EstimateGenerationStatus::Applying => $this->retryApply($session),
                    default => throw new InvalidEstimateGenerationState($session->status, 'retry'),
                };
            },
        );
    }

    private function retryInputReview(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $documentIds = $session->documents
            ->filter(static fn ($document): bool => in_array((string) $document->status, [
                'uploaded', 'queued', 'processing', 'failed', 'needs_review',
            ], true))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($documentIds === [] && ! $this->hasSufficientGenerationInput($session)) {
            return $session;
        }

        $session = $this->workflow->transition($session, EstimateGenerationEvent::Retried, [
            'processing_stage' => 'processing_documents',
            'processing_progress' => 5,
            'last_error' => null,
            'failure_code' => null,
        ]);

        if ($documentIds !== []) {
            $this->dispatcher->dispatchDocuments($documentIds);

            return $session;
        }

        $session = $this->workflow->transition($session, EstimateGenerationEvent::DocumentsReady, [
            'processing_stage' => 'ready_to_generate',
            'processing_progress' => 35,
            'last_error' => null,
            'failure_code' => null,
        ]);

        return $this->startGeneration($session);
    }

    private function hasSufficientGenerationInput(EstimateGenerationSession $session): bool
    {
        if (trim((string) ($session->input_payload['description'] ?? '')) !== '') {
            return true;
        }

        return $session->documents->contains(
            static fn ($document): bool => (string) $document->status === 'ready',
        );
    }

    private function retryDocuments(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $actionRequired = $session->documents->contains(
            static fn ($document): bool => (string) $document->status === 'needs_review',
        );
        $session = $this->workflow->transition($session, EstimateGenerationEvent::Retried, [
            'processing_stage' => 'processing_documents',
            'processing_progress' => 5,
            'last_error' => null,
            'failure_code' => null,
        ]);

        if ($actionRequired) {
            return $this->workflow->transition($session, EstimateGenerationEvent::DocumentsNeedReview, [
                'processing_stage' => 'input_review_required',
                'processing_progress' => 35,
            ]);
        }

        $documentIds = $session->documents
            ->filter(static fn ($document): bool => in_array((string) $document->status, [
                'uploaded', 'queued', 'processing', 'failed',
            ], true))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($documentIds === []) {
            $session = $this->workflow->transition($session, EstimateGenerationEvent::DocumentsReady, [
                'processing_stage' => 'ready_to_generate',
                'processing_progress' => 35,
                'last_error' => null,
                'failure_code' => null,
            ]);

            return $this->startGeneration($session);
        }

        $this->dispatcher->dispatchDocuments($documentIds);

        return $session;
    }

    private function startGeneration(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $attemptId = ($this->attemptIdFactory)();
        $session = $this->workflow->transition($session, EstimateGenerationEvent::GenerationStarted, [
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
        $this->dispatcher->dispatchGeneration((int) $session->getKey(), (int) $session->state_version, $attemptId);

        return $session;
    }

    private function retryGeneration(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $attemptId = ($this->attemptIdFactory)();
        $session = $this->workflow->transition($session, EstimateGenerationEvent::Retried, [
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
        $this->dispatcher->dispatchGeneration((int) $session->getKey(), (int) $session->state_version, $attemptId);

        return $session;
    }

    private function restartGeneration(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $attemptId = ($this->attemptIdFactory)();
        $session = $this->workflow->update($session, [EstimateGenerationStatus::Generating], [
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
        $this->dispatcher->dispatchGeneration((int) $session->getKey(), (int) $session->state_version, $attemptId);

        return $session;
    }

    private function retryApply(EstimateGenerationSession $session): EstimateGenerationSession
    {
        return $this->workflow->transition($session, EstimateGenerationEvent::Retried, [
            'processing_stage' => 'ready_to_apply',
            'processing_progress' => 100,
            'last_error' => null,
            'failure_code' => null,
        ]);
    }
}
