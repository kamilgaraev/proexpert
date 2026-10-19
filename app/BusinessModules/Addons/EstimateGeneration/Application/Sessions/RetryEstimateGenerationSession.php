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
use Illuminate\Auth\Access\AuthorizationException;
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
        private ?EstimateGenerationActionAuthorization $authorizer = null,
    ) {
        $this->attemptIdFactory = $attemptIdFactory ?? static fn (): string => (string) Str::uuid();
    }

    public function handle(RetryEstimateGenerationSessionCommand $command): EstimateGenerationSession
    {
        if ($command->actor === null) {
            throw new AuthorizationException(trans_message('estimate_generation.access_denied'));
        }
        $reconcileImmediately = false;
        $session = $this->repository->withLockedSession(
            $command->sessionId,
            $command->organizationId,
            $command->projectId,
            function (EstimateGenerationSession $session) use ($command, &$reconcileImmediately): EstimateGenerationSession {
                $preserveRegionalContext = false;
                ($this->authorizer ?? app(EstimateGenerationActionAuthorization::class))
                    ->authorize($command->actor, $session, 'estimate_generation.generate');
                $creditEnabled = \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $session->organization_id);
                $freeApplyRecovery = $session->status === EstimateGenerationStatus::Failed && $session->resume_status === EstimateGenerationStatus::Applying;
                if (! $creditEnabled && ! $freeApplyRecovery) {
                    $session->input_payload = [...($session->input_payload ?? []), 'generation_credit_operation_id' => null];
                }
                if ($creditEnabled && ! $freeApplyRecovery) {
                    if (! $command->actor instanceof \App\Models\User || ! is_array($command->creditApproval)
                        || ! Str::isUuid($command->creditApproval['quote_id'] ?? '') || ! Str::isUuid($command->creditApproval['request_id'] ?? '')) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Повторный платный запуск требует подтверждения нового предложения стоимости.']);
                    }
                    $operationId = strtolower($command->creditApproval['request_id']);
                    $previous = \Illuminate\Support\Facades\DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)
                        ->where('organization_id', $command->organizationId)->where('project_id', $command->projectId)->where('session_id', $command->sessionId)
                        ->where('actor_user_id', $command->actor->id)->where('kind', 'generation')->first();
                    if ($previous !== null && $previous->receipt !== null) {
                        $quote = \App\Models\Credits\AICreditQuote::query()->findOrFail($previous->quote_id);
                        if ($quote->public_id !== strtolower($command->creditApproval['quote_id']) || (int) $previous->approved_state_version !== $command->expectedStateVersion) {
                            throw \Illuminate\Validation\ValidationException::withMessages(['request_id' => 'Идентификатор относится к другой операции.']);
                        }

                        return $session;
                    }
                    if ($session->status === EstimateGenerationStatus::Generating
                        || $session->status === EstimateGenerationStatus::InputReviewRequired
                        || ($session->status === EstimateGenerationStatus::Failed && $session->resume_status === EstimateGenerationStatus::ProcessingDocuments)) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Сначала подготовьте документы либо проверьте исход активной операции.']);
                    }
                    try {
                        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                            ->begin($command->actor, $session, $command->expectedStateVersion, $operationId, $command->creditApproval['quote_id'], 'generation');
                    } catch (\DomainException $exception) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Предложение стоимости недействительно либо баланса недостаточно.']);
                    }
                    $plan = $previous === null ? [] : json_decode($previous->processing_plan, true, 64, JSON_THROW_ON_ERROR);
                    $preserveRegionalContext = isset($plan['retained_pipeline_prefix']);
                    $session->input_payload = [...($session->input_payload ?? []), 'generation_credit_operation_id' => $operationId];
                    \Illuminate\Support\Facades\DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)
                        ->update(['receipt' => \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode([
                            'operation_id' => $operationId, 'status' => 'queued']), 'updated_at' => now()]);
                }
                if ($session->state_version !== $command->expectedStateVersion) {
                    throw new StaleEstimateGenerationState((int) $session->getKey(), $command->expectedStateVersion);
                }
                $session->input_payload = [...($session->input_payload ?? []), ...EstimateGenerationExecutionActor::identity($command->actor)];
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
                    EstimateGenerationStatus::Generating => $this->retryGeneration($session, $preserveRegionalContext),
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
        if (($session->input_payload['input_confirmed'] ?? null) === false) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'session' => trans_message('estimate_generation.prerequisites.input_confirmation_required'),
            ]);
        }
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
            'input_payload' => $session->input_payload,
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
            'input_payload' => $session->input_payload,
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
        $attemptId = $this->attemptId($session);
        $session = $this->advance->generationStarted($session, $attemptId, $this->refreshedRegionalContext($session));
        $this->dispatcher->dispatchGeneration((int) $session->getKey(), (int) $session->state_version, $attemptId);

        return $session;
    }

    private function retryGeneration(EstimateGenerationSession $session, bool $preserveRegionalContext = false): EstimateGenerationSession
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
        $attemptId = $this->attemptId($session);
        $session = $this->advance->generationRetried(
            $session,
            $attemptId,
            $preserveRegionalContext ? [] : $this->refreshedRegionalContext($session),
            $analysisPayload,
        );
        $this->dispatcher->dispatchGeneration((int) $session->getKey(), (int) $session->state_version, $attemptId);

        return $session;
    }

    private function restartGeneration(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $attemptId = $this->attemptId($session);
        $session = $this->advance->generationRestarted($session, $attemptId, $this->refreshedRegionalContext($session));
        $this->dispatcher->dispatchGeneration((int) $session->getKey(), (int) $session->state_version, $attemptId);

        return $session;
    }

    private function attemptId(EstimateGenerationSession $session): string
    {
        if (! \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $session->organization_id)) {
            return ($this->attemptIdFactory)();
        }
        $operationId = $session->input_payload['generation_credit_operation_id'] ?? null;
        if (! is_string($operationId) || ! Str::isUuid($operationId)) {
            throw new \DomainException('estimator_credit_retry_operation_missing');
        }

        return $operationId;
    }

    private function refreshedRegionalContext(EstimateGenerationSession $session): array
    {
        return $this->regionalContextResolver->retryChanges($session->input_payload ?? []);
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
