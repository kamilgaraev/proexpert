<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateInterpretationAttemptRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiWireNotStarted;
use App\Models\SystemAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class InterpretEstimateCommand
{
    public function __construct(
        private EstimateCommandInterpreter $interpreter,
        private PreviewEstimateChange $preview,
        private EstimateChangeProposalRepository $proposals,
        private EstimateInterpretationAttemptRepository $attempts,
        private EstimateCommandContextBuilder $contexts,
    ) {}

    /** @return array<string, mixed> */
    public function handle(EstimateGenerationSession $session, int|User|SystemAdmin $actor, string $command, string $idempotencyKey, ?array $approval = null): array
    {
        $actorId = is_int($actor) ? $actor : (int) $actor->id;
        if (! is_int($actor)) {
            app(EstimateGenerationActionAuthorization::class)->authorize($actor, $session, 'estimate_generation.review');
        } elseif ($this->interpreter instanceof GuardedEstimateCommandInterpreter) {
            throw new AiWireNotStarted('dialogue_trusted_actor_required');
        }
        $context = $this->contexts->build($session);
        $creditOperationId = $this->approveCreditOperation($session, $actor, $command, $idempotencyKey, $context, $approval);
        if ($creditOperationId !== null) {
            $context['credit_operation_id'] = $creditOperationId;
        }
        $fingerprint = hash('sha256', json_encode(['command' => $command, 'context_fingerprint' => $context['fingerprint']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $existing = $this->proposals->findByIdempotency(
            (int) $session->organization_id,
            (int) $session->project_id,
            (int) $session->id,
            $idempotencyKey,
        );
        if ($existing !== null) {
            if (! hash_equals((string) $existing->payload['payload_fingerprint'], $fingerprint)) {
                throw new RuntimeException('estimate_generation.proposal_idempotency_collision');
            }

            return $this->publishReplayedResult($session, $creditOperationId, ['kind' => 'proposal', 'proposal' => $existing->payload]);
        }
        $owner = (string) Str::uuid();
        $claim = $this->attempts->claim((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner);
        if ($claim['action'] === 'replay' && is_array($claim['result'] ?? null)) {
            return $this->publishReplayedResult($session, $creditOperationId, $claim['result']);
        }
        if ($claim['action'] === 'ambiguous') {
            $recovered = $this->interpreter instanceof GuardedEstimateCommandInterpreter && ! is_int($actor)
                ? $this->interpreter->recoverReceivedResponse($session, $actor, $command, $context, $idempotencyKey) : null;
            if ($recovered !== null) {
                $this->attempts->acceptRecoveredResponse((int) $session->organization_id, (int) $session->project_id, (int) $session->id,
                    $idempotencyKey, $fingerprint, $owner, $recovered->payload);
                $claim = ['action' => 'resume', 'interpretation' => $recovered->payload];
            } else {
                throw new InterpretEstimateCommandFailure(
                    'estimate_generation.interpretation_attempt_ambiguous',
                    'attempt_ambiguous_no_retry',
                );
            }
        }
        if (! in_array($claim['action'], ['owned', 'resume'], true)) {
            return [
                'kind' => 'in_progress',
                'status' => $claim['action'],
                'retryable' => false,
                'retry_disposition' => 'attempt_in_progress',
            ];
        }
        if ($claim['action'] === 'resume') {
            if (! is_array($claim['interpretation'] ?? null)) {
                throw new RuntimeException('estimate_generation.interpretation_response_invalid');
            }
            $interpretation = new EstimateCommandInterpretation($claim['interpretation']);
        } else {
            $beforeSend = fn () => $this->attempts->markWireStarted((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner);
            $received = false;
            try {
                if ($this->interpreter instanceof GuardedEstimateCommandInterpreter && ! is_int($actor)) {
                    $interpretation = $this->interpreter->interpretWithAttempt($session, $actor, $command, $context, $idempotencyKey, $beforeSend);
                } else {
                    $beforeSend();
                    $interpretation = $this->interpreter->interpret($session, $actorId, $command, $context);
                }
                $received = true;
                $this->attempts->storeResponse((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner, $interpretation->payload);
            } catch (AiWireNotStarted $exception) {
                $this->attempts->releaseBeforeWire((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner);
                $this->abortCreditOperation($session, $creditOperationId, 'dialogue_not_sent');
                throw new InterpretEstimateCommandFailure('estimate_generation.interpretation_not_sent', $creditOperationId === null ? 'retry_same_attempt' : 'terminal_new_attempt_allowed', $exception);
            } catch (\Throwable $exception) {
                $this->attempts->markAmbiguous((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner);
                if (! $received) {
                    $this->abortCreditOperation($session, $creditOperationId, 'dialogue_outcome_unknown');
                }
                throw new InterpretEstimateCommandFailure(
                    'estimate_generation.interpretation_attempt_ambiguous',
                    'attempt_ambiguous_no_retry',
                    $exception,
                );
            }
        }
        try {
            return DB::transaction(function () use ($session, $actor, $actorId, $context, $interpretation, $command, $idempotencyKey, $fingerprint, $owner, $creditOperationId): array {
                $current = EstimateGenerationSession::query()->where('organization_id', $session->organization_id)
                    ->where('project_id', $session->project_id)->whereKey($session->id)->lockForUpdate()->firstOrFail();
                if ($current->status->isTerminal() || $current->status->value === 'applying'
                    || $current->state_version !== ($context['snapshot']['state_version'] ?? null)) {
                    throw new RuntimeException('estimate_generation.interpretation_source_changed');
                }
                if (! is_int($actor)) {
                    app(EstimateGenerationActionAuthorization::class)->authorize($actor, $current, 'estimate_generation.review');
                }
                if ($interpretation->kind() === 'explain') {
                    $result = ['kind' => 'explanation', 'explanation' => mb_substr((string) ($interpretation->payload['explanation'] ?? ''), 0, 8000), 'evidence' => array_slice(is_array($interpretation->payload['evidence'] ?? null) ? $interpretation->payload['evidence'] : [], 0, 100), 'read_only' => true];
                    $this->attempts->complete((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner, $result);
                    if ($creditOperationId !== null) {
                        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)->publishDialogue($current, $creditOperationId, $result);
                    }

                    return $result;
                }

                $result = ['kind' => 'proposal', 'proposal' => $this->preview->handle($current, $actorId, $command, $idempotencyKey, $interpretation)->payload];
                $this->attempts->complete((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner, $result);
                if ($creditOperationId !== null) {
                    app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)->publishDialogue($current, $creditOperationId, $result);
                }

                return $result;
            }, 3);
        } catch (InterpretEstimateCommandFailure $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException
                || $exception->getMessage() === 'estimate_generation.interpretation_source_changed') {
                $this->abortCreditOperation($session, $creditOperationId, 'dialogue_publication_not_allowed');
            }
            throw new InterpretEstimateCommandFailure(
                'estimate_generation.interpretation_publication_failed',
                'retry_same_attempt',
                $exception,
            );
        }
    }

    private function approveCreditOperation(EstimateGenerationSession $session, int|User|SystemAdmin $actor, string $command, string $key, array $context, ?array $approval): ?string
    {
        if (! \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $session->organization_id)) {
            return null;
        }
        if (! $actor instanceof User || ! is_array($approval) || ! Str::isUuid($key)
            || ($approval['request_id'] ?? null) !== $key || ! Str::isUuid($approval['quote_id'] ?? '')
            || ! is_int($approval['state_version'] ?? null) || $approval['state_version'] !== ($context['snapshot']['state_version'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Подтвердите стоимость уточнения перед обращением к ИИ.']);
        }

        return DB::transaction(function () use ($session, $actor, $command, $key, $context, $approval): string {
            $current = EstimateGenerationSession::query()->whereKey($session->id)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $session->project_id)->lockForUpdate()->firstOrFail();
            app(EstimateGenerationActionAuthorization::class)->authorize($actor, $current, 'estimate_generation.review');
            if ($current->status->isTerminal() || in_array($current->status->value, ['generating', 'applying', 'failed'], true)
                || $current->state_version !== $approval['state_version']
                || $this->contexts->build($current)['fingerprint'] !== $context['fingerprint']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['state_version' => 'Исходные данные уточнения изменились.']);
            }
            $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', strtolower($key))
                ->where('organization_id', $current->organization_id)->where('project_id', $current->project_id)
                ->where('session_id', $current->id)->where('actor_user_id', $actor->id)->where('kind', 'dialogue')->lockForUpdate()->first();
            if ($operation?->status === 'settled') {
                $quote = \App\Models\Credits\AICreditQuote::query()->whereKey($operation->quote_id)->firstOrFail();
                $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
                if ($quote->public_id !== strtolower($approval['quote_id']) || (int) $operation->approved_state_version !== $approval['state_version']
                    || ($plan['operation_parameters']['command_hash'] ?? null) !== hash('sha256', $command)
                    || ($plan['operation_parameters']['idempotency_key'] ?? null) !== $key
                    || ($plan['operation_parameters']['context_fingerprint'] ?? null) !== $context['fingerprint']) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['request_id' => 'Идентификатор относится к другому уточнению.']);
                }

                return strtolower($key);
            }
            try {
                return app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                    ->begin($actor, $current, $approval['state_version'], $key, $approval['quote_id'], 'dialogue', ['command' => $command, 'idempotency_key' => $key]);
            } catch (\DomainException) {
                throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Предложение стоимости уточнения недействительно.']);
            }
        }, 3);
    }

    private function abortCreditOperation(EstimateGenerationSession $session, ?string $operationId, string $reason): void
    {
        if ($operationId !== null) {
            DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
                ->abort($session, $operationId, $reason), 3);
        }
    }

    private function publishReplayedResult(EstimateGenerationSession $session, ?string $operationId, array $result): array
    {
        if ($operationId !== null) {
            DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
                ->publishDialogue($session, $operationId, $result), 3);
        }

        return $result;
    }
}
