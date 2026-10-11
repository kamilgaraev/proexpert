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
    public function handle(EstimateGenerationSession $session, int|User|SystemAdmin $actor, string $command, string $idempotencyKey): array
    {
        $actorId = is_int($actor) ? $actor : (int) $actor->id;
        if (! is_int($actor)) {
            app(EstimateGenerationActionAuthorization::class)->authorize($actor, $session, 'estimate_generation.review');
        } elseif ($this->interpreter instanceof GuardedEstimateCommandInterpreter) {
            throw new AiWireNotStarted('dialogue_trusted_actor_required');
        }
        $context = $this->contexts->build($session);
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

            return ['kind' => 'proposal', 'proposal' => $existing->payload];
        }
        $owner = (string) Str::uuid();
        $claim = $this->attempts->claim((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner);
        if ($claim['action'] === 'replay' && is_array($claim['result'] ?? null)) {
            return $claim['result'];
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
            try {
                if ($this->interpreter instanceof GuardedEstimateCommandInterpreter && ! is_int($actor)) {
                    $interpretation = $this->interpreter->interpretWithAttempt($session, $actor, $command, $context, $idempotencyKey, $beforeSend);
                } else {
                    $beforeSend();
                    $interpretation = $this->interpreter->interpret($session, $actorId, $command, $context);
                }
                $this->attempts->storeResponse((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner, $interpretation->payload);
            } catch (AiWireNotStarted $exception) {
                $this->attempts->releaseBeforeWire((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner);
                throw new InterpretEstimateCommandFailure('estimate_generation.interpretation_not_sent', 'retry_same_attempt', $exception);
            } catch (\Throwable $exception) {
                $this->attempts->markAmbiguous((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner);
                throw new InterpretEstimateCommandFailure(
                    'estimate_generation.interpretation_attempt_ambiguous',
                    'attempt_ambiguous_no_retry',
                    $exception,
                );
            }
        }
        try {
            return DB::transaction(function () use ($session, $actor, $actorId, $context, $interpretation, $command, $idempotencyKey, $fingerprint, $owner): array {
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

                    return $result;
                }

                $result = ['kind' => 'proposal', 'proposal' => $this->preview->handle($current, $actorId, $command, $idempotencyKey, $interpretation)->payload];
                $this->attempts->complete((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $idempotencyKey, $fingerprint, $owner, $result);

                return $result;
            }, 3);
        } catch (InterpretEstimateCommandFailure $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InterpretEstimateCommandFailure(
                'estimate_generation.interpretation_publication_failed',
                'retry_same_attempt',
                $exception,
            );
        }
    }
}
