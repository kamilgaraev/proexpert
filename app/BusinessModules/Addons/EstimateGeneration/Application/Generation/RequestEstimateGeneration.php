<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationExecutionActor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionActionResult;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Services\SelectedRegionalPriceContext;
use App\Models\User;
use Illuminate\Support\Str;

final class RequestEstimateGeneration
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private AdvanceEstimateGeneration $advance,
        private DocumentGenerationReadinessService $readiness,
        private EstimateGenerationRegionalContextResolver $regionalContextResolver,
        private SelectedRegionalPriceContext $selectedRegionalPriceContext,
        private EstimateGenerationActionAuthorization $authorizer,
    ) {}

    public function handle(
        EstimateGenerationSession $session,
        int $expectedVersion,
        ?string $requestedMode,
        User $actor,
        ?int $selectedRegionalPriceVersionId = null,
        ?array $creditApproval = null,
        string $creditKind = 'generation',
    ): SessionActionResult {
        if (config('estimate-generation.credits.enabled', false)) {
            if (! is_array($creditApproval) || ! Str::isUuid($creditApproval['request_id'] ?? '') || ! Str::isUuid($creditApproval['quote_id'] ?? '')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Перед запуском подтвердите предложение стоимости этой операции.']);
            }

            return \Illuminate\Support\Facades\DB::transaction(function () use ($session, $expectedVersion, $requestedMode, $actor,
                $selectedRegionalPriceVersionId, $creditApproval, $creditKind): SessionActionResult {
                $locked = EstimateGenerationSession::query()->whereKey($session->id)->where('organization_id', $actor->current_organization_id)
                    ->where('project_id', $session->project_id)->lockForUpdate()->firstOrFail();
                $this->authorizer->authorize($actor, $locked, 'estimate_generation.generate');
                $operationId = strtolower($creditApproval['request_id']);
                $prior = \Illuminate\Support\Facades\DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)
                    ->where('organization_id', $locked->organization_id)->where('project_id', $locked->project_id)
                    ->where('session_id', $locked->id)->where('actor_user_id', $actor->id)->first();
                if ($prior !== null && $prior->receipt !== null) {
                    $quote = \App\Models\Credits\AICreditQuote::query()->whereKey($prior->quote_id)->firstOrFail();
                    if ($quote->public_id !== strtolower($creditApproval['quote_id']) || $prior->kind !== $creditKind
                        || (int) $prior->approved_state_version !== $expectedVersion) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['request_id' => 'Идентификатор относится к другой операции.']);
                    }

                    return new SessionActionResult($locked, true, 'estimate_generation.generation_queued', 200,
                        ['operation_id' => $operationId, 'operation_receipt' => json_decode($prior->receipt, true, 64, JSON_THROW_ON_ERROR)]);
                }
                if ((int) $locked->state_version !== $expectedVersion || ($requestedMode !== null && $requestedMode !== ($locked->input_payload['generation_mode'] ?? null))
                    || $selectedRegionalPriceVersionId !== null) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Сначала сохраните параметры и источник цен, затем получите предложение стоимости.']);
                }
                if ($locked->status === EstimateGenerationStatus::Generating
                    && ($locked->input_payload['generation_credit_operation_id'] ?? null) !== $operationId) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['request_id' => 'В этой сессии уже выполняется другая операция.']);
                }
                $approved = clone $locked;
                $result = $this->execute($locked, $expectedVersion, $requestedMode, $actor, null, $operationId);
                if (! $result->successful) {
                    return $result;
                }
                $inputs = new EvaluationInputFingerprint;
                if (! hash_equals($inputs->fromSession($approved), $inputs->fromSession($result->session))) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Исходные параметры изменились. Сохраните их и получите новое предложение стоимости.']);
                }
                try {
                    app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                        ->begin($actor, $approved, $expectedVersion, $operationId, $creditApproval['quote_id'], $creditKind);
                } catch (\DomainException $exception) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Предложение стоимости недействительно либо баланса недостаточно. Получите актуальное предложение.']);
                }
                \Illuminate\Support\Facades\DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->update([
                    'receipt' => \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode([
                        'operation_id' => $operationId, 'status' => 'queued', 'state_version' => (int) $result->session->state_version]), 'updated_at' => now()]);

                return $result;
            }, 3);
        }

        return $this->execute($session, $expectedVersion, $requestedMode, $actor, $selectedRegionalPriceVersionId);
    }

    private function execute(EstimateGenerationSession $session, int $expectedVersion, ?string $requestedMode, User $actor,
        ?int $selectedRegionalPriceVersionId = null, ?string $creditOperationId = null): SessionActionResult
    {
        $this->authorizer->authorize($actor, $session, 'estimate_generation.generate');
        if (($session->input_payload['evaluation_mode'] ?? null) === 'universal' && ! config('estimate-generation.universal_enabled', false)) {
            throw new InvalidEstimateGenerationState($session->status, 'universal_evaluation_not_enabled');
        }

        if ($session->status === EstimateGenerationStatus::Generating) {
            if ($selectedRegionalPriceVersionId !== null) {
                throw new InvalidEstimateGenerationState($session->status, 'price_source_change_during_generation');
            }

            if (trim((string) ($session->input_payload['generation_attempt_id'] ?? '')) === '') {
                throw new InvalidEstimateGenerationState($session->status, 'generation_retry_without_attempt');
            }

            return new SessionActionResult($session, true, 'estimate_generation.generation_queued', 202);
        }

        $this->policy->generate($session, $expectedVersion);
        $generationMode = EstimateGenerationMode::fromInput(
            $requestedMode ?? ($session->input_payload['generation_mode'] ?? null),
        )->value;
        $generationInput = EstimateGenerationExecutionActor::identity($actor);
        if ($creditOperationId !== null) {
            $generationInput['generation_credit_operation_id'] = $creditOperationId;
        }
        if (($session->input_payload['generation_mode'] ?? null) !== $generationMode) {
            if ($session->status === EstimateGenerationStatus::Applied) {
                $generationInput['generation_mode'] = $generationMode;
            } else {
                $session = $this->advance->update($session, [
                    EstimateGenerationStatus::Draft,
                    EstimateGenerationStatus::ProcessingDocuments,
                    EstimateGenerationStatus::ReadyToGenerate,
                    EstimateGenerationStatus::EstimateReviewRequired,
                    EstimateGenerationStatus::ReadyToApply,
                    EstimateGenerationStatus::Cancelled,
                ], ['input_payload' => [...($session->input_payload ?? []), 'generation_mode' => $generationMode]]);
            }
        }

        if ($selectedRegionalPriceVersionId !== null) {
            $session = $this->advance->update($session, [
                EstimateGenerationStatus::Draft,
                EstimateGenerationStatus::ProcessingDocuments,
                EstimateGenerationStatus::ReadyToGenerate,
                EstimateGenerationStatus::EstimateReviewRequired,
                EstimateGenerationStatus::ReadyToApply,
                EstimateGenerationStatus::Cancelled,
                EstimateGenerationStatus::Applied,
            ], ['input_payload' => $this->selectedRegionalPriceContext->replace(
                $session->input_payload ?? [],
                $selectedRegionalPriceVersionId,
            )]);
        }

        $session = $this->refreshRegionalContext($session);

        $readiness = $this->readiness->evaluate($session->load('documents'));
        if (! $readiness['can_generate']) {
            if ($this->canWait($session, $readiness['summary'])) {
                $session = $this->advance->documentsStarted($session, ['input_payload' => [
                    ...($session->input_payload ?? []),
                    ...$generationInput,
                    'generation_requested' => true,
                ]]);

                return new SessionActionResult($session, true, 'estimate_generation.generation_documents_pending', 202);
            }

            return new SessionActionResult($session, false, $readiness['blocking_message_key'] ?? 'estimate_generation.documents_require_action', 409, ['documents_summary' => $readiness['summary']]);
        }
        if (! $this->hasInput($session, $readiness['summary'])) {
            return new SessionActionResult($session, false, 'estimate_generation.input_required', 422, ['documents_summary' => $readiness['summary']]);
        }

        $session = $this->advance->documentsReady($session);
        if (($session->input_payload['evaluation_mode'] ?? null) === 'universal') {
            $generationInput['generation_evaluation_input_hash'] = (new EvaluationInputFingerprint)->fromSession($session);
        }
        $attemptId = $creditOperationId ?? (string) Str::uuid();
        $session = $this->advance->generationStarted($session, $attemptId, $generationInput);
        GenerateEstimateDraftJob::dispatch(
            (int) $session->getKey(),
            $session->state_version,
            $attemptId,
            FailureExecutionSnapshot::capture($session, 'generate_draft', $attemptId),
        )
            ->onQueue(GenerateEstimateDraftJob::QUEUE)
            ->afterCommit();

        return new SessionActionResult($session, true, 'estimate_generation.generation_queued', 202);
    }

    private function refreshRegionalContext(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $regionalContext = $session->input_payload['regional_context'] ?? null;

        if (! is_array($regionalContext)) {
            return $session;
        }

        $refreshedContext = [
            ...$regionalContext,
            ...$this->regionalContextResolver->resolve([
                ...($session->input_payload ?? []),
                ...$regionalContext,
            ]),
        ];

        if ($refreshedContext === $regionalContext) {
            return $session;
        }

        return $this->advance->update($session, [
            EstimateGenerationStatus::Draft,
            EstimateGenerationStatus::ProcessingDocuments,
            EstimateGenerationStatus::ReadyToGenerate,
            EstimateGenerationStatus::EstimateReviewRequired,
            EstimateGenerationStatus::ReadyToApply,
            EstimateGenerationStatus::Cancelled,
            EstimateGenerationStatus::Applied,
        ], ['input_payload' => [
            ...($session->input_payload ?? []),
            'regional_context' => $refreshedContext,
        ]]);
    }

    /** @param array<string, mixed> $summary */
    private function hasInput(EstimateGenerationSession $session, array $summary): bool
    {
        return trim((string) ($session->input_payload['description'] ?? '')) !== '' || (int) ($summary['ready_count'] ?? 0) > 0;
    }

    /** @param array<string, mixed> $summary */
    private function canWait(EstimateGenerationSession $session, array $summary): bool
    {
        return (int) ($summary['pending_count'] ?? 0) > 0
            && (int) ($summary['action_required_count'] ?? 0) === 0
            && (trim((string) ($session->input_payload['description'] ?? '')) !== '' || (int) ($summary['total'] ?? 0) > 0);
    }
}
