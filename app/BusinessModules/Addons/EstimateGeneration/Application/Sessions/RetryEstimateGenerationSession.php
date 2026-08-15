<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EstimateGenerationSessionReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use Closure;
use Illuminate\Support\Str;

final class RetryEstimateGenerationSession
{
    private Closure $attemptIdFactory;

    public function __construct(
        private RetryableEstimateGenerationSessionRepository $repository,
        private EstimateGenerationWorkflow $workflow,
        private AdvanceEstimateGeneration $advance,
        private EstimateGenerationRetryDispatcher $dispatcher,
        private EstimateGenerationSessionReconciler $reconciler,
        private EstimateGenerationRegionalContextResolver $regionalContextResolver,
        ?Closure $attemptIdFactory = null,
    ) {
        $this->attemptIdFactory = $attemptIdFactory ?? static fn (): string => (string) Str::uuid();
    }

    public function handle(RetryEstimateGenerationSessionCommand $command): EstimateGenerationSession
    {
        $reconcileImmediately = false;
        $session = $this->repository->withLockedSession(
            $command->sessionId,
            $command->organizationId,
            $command->projectId,
            function (EstimateGenerationSession $session) use ($command, &$reconcileImmediately): EstimateGenerationSession {
                if ($session->state_version !== $command->expectedStateVersion) {
                    throw new StaleEstimateGenerationState((int) $session->getKey(), $command->expectedStateVersion);
                }
                if ($session->status === EstimateGenerationStatus::InputReviewRequired) {
                    return $this->retryInputReview($session, $reconcileImmediately);
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

        return $reconcileImmediately ? $this->reconciler->reconcile($session) : $session;
    }

    private function retryInputReview(
        EstimateGenerationSession $session,
        bool &$reconcileImmediately,
    ): EstimateGenerationSession {
        $requiresPlanningRecovery = $this->hasBlockedPlanningReview($session);
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

        if ($requiresPlanningRecovery) {
            $reconcileImmediately = true;

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
        $session = $this->advance->generationStarted($session, $attemptId, $this->refreshedRegionalContext($session));
        $this->dispatcher->dispatchGeneration((int) $session->getKey(), (int) $session->state_version, $attemptId);

        return $session;
    }

    private function retryGeneration(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $analysisPayload = null;
        if ((string) $session->failure_code === 'session_cost_limit_reached') {
            $analysis = is_array($session->analysis_payload) ? $session->analysis_payload : [];
            $guard = is_array($analysis['internal_cost_guard'] ?? null)
                ? $analysis['internal_cost_guard']
                : [];
            $analysisPayload = [
                ...$analysis,
                'internal_cost_guard' => [
                    ...$guard,
                    'confirmation_version' => max(0, (int) ($guard['confirmation_version'] ?? 0)) + 1,
                    'confirmed_at' => now()->toISOString(),
                ],
            ];
        }
        $attemptId = ($this->attemptIdFactory)();
        $session = $this->advance->generationRetried(
            $session,
            $attemptId,
            $this->refreshedRegionalContext($session),
            $analysisPayload,
        );
        $this->dispatcher->dispatchGeneration((int) $session->getKey(), (int) $session->state_version, $attemptId);

        return $session;
    }

    private function restartGeneration(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $attemptId = ($this->attemptIdFactory)();
        $session = $this->advance->generationRestarted($session, $attemptId, $this->refreshedRegionalContext($session));
        $this->dispatcher->dispatchGeneration((int) $session->getKey(), (int) $session->state_version, $attemptId);

        return $session;
    }

    private function refreshedRegionalContext(EstimateGenerationSession $session): array
    {
        $regionalContext = $session->input_payload['regional_context'] ?? null;

        if (! is_array($regionalContext)) {
            return [];
        }

        return [
            'regional_context' => [
                ...$regionalContext,
                ...$this->regionalContextResolver->resolve([
                    ...($session->input_payload ?? []),
                    ...$regionalContext,
                ]),
            ],
        ];
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

    private function hasBlockedPlanningReview(EstimateGenerationSession $session): bool
    {
        $review = is_array($session->input_payload['planning_review'] ?? null)
            ? $session->input_payload['planning_review']
            : [];

        return ($review['status'] ?? null) === 'blocked';
    }
}
