<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class EloquentAdminFailureResolutionTransaction implements AdminFailureResolutionTransaction
{
    private const EVENT_TYPE = 'admin_failure_resolution';

    public function __construct(private ConnectionInterface $database) {}

    public function execute(AdminFailureResolutionCommand $command, callable $resolution): AdminFailureResolutionResult
    {
        return $this->database->transaction(function () use ($command, $resolution): AdminFailureResolutionResult {
            $identity = $this->database->table('estimate_generation_failure_identities')
                ->select(['id', 'fingerprint', 'organization_id', 'project_id', 'session_id'])
                ->where('id', $command->failureId)
                ->where('organization_id', $command->organizationId)
                ->where('project_id', $command->projectId)
                ->where('session_id', $command->sessionId)
                ->lockForUpdate()
                ->first();
            if ($identity === null) {
                return AdminFailureResolutionResult::failure('estimate_generation.admin_operation_not_found');
            }

            $replay = EstimateGenerationAuditEvent::query()
                ->select(['id', 'payload'])
                ->where('session_id', $command->sessionId)
                ->where('event_type', self::EVENT_TYPE)
                ->where('payload->idempotency_key', $command->idempotencyKey)
                ->orderByDesc('id')
                ->limit(1)
                ->first();
            if ($replay instanceof EstimateGenerationAuditEvent && is_array($replay->payload['result'] ?? null)) {
                return AdminFailureResolutionResult::fromArray($replay->payload['result']);
            }

            $latestOccurrence = $this->database->table('estimate_generation_failure_events')
                ->select(['sequence', 'attempt'])
                ->where('failure_id', $command->failureId)
                ->where('event_type', 'occurred')
                ->orderByDesc('sequence')
                ->limit(1)
                ->first();
            $latestResolution = $this->database->table('estimate_generation_failure_events')
                ->where('failure_id', $command->failureId)
                ->where('event_type', 'resolved')
                ->max('resolves_through_sequence');
            $latestOccurrenceSequence = $latestOccurrence === null ? 0 : (int) $latestOccurrence->sequence;
            $snapshot = new AdminFailureResolutionSnapshot(
                (string) $identity->id,
                (int) $identity->organization_id,
                (int) $identity->project_id,
                (int) $identity->session_id,
                $latestOccurrenceSequence,
                $latestOccurrenceSequence > (int) ($latestResolution ?? 0),
            );
            $result = $resolution($snapshot, function () use ($command, $identity, $latestOccurrence, $latestOccurrenceSequence): void {
                if ($latestOccurrence === null) {
                    throw new \LogicException('Active failure occurrence is required.');
                }
                $this->database->table('estimate_generation_failure_events')->insert([
                    'event_id' => (string) Str::uuid(),
                    'correlation_id' => (string) Str::uuid(),
                    'failure_id' => $command->failureId,
                    'fingerprint' => (string) $identity->fingerprint,
                    'organization_id' => $command->organizationId,
                    'project_id' => $command->projectId,
                    'session_id' => $command->sessionId,
                    'event_type' => 'resolved',
                    'attempt' => (int) $latestOccurrence->attempt,
                    'safe_context' => '{}',
                    'resolution_code' => 'admin_confirmed_resolved',
                    'resolves_through_sequence' => $latestOccurrenceSequence,
                    'recorded_at' => now(),
                ]);
            });

            EstimateGenerationAuditEvent::query()->create([
                'session_id' => $command->sessionId,
                'user_id' => null,
                'event_type' => self::EVENT_TYPE,
                'payload' => [
                    'actor_type' => 'system_admin',
                    'actor_id' => $command->actorId,
                    'organization_id' => $command->organizationId,
                    'project_id' => $command->projectId,
                    'failure_id' => $command->failureId,
                    'occurrence_sequence' => $command->expectedOccurrenceSequence,
                    'idempotency_key' => $command->idempotencyKey,
                    'result' => $result->toArray(),
                ],
            ]);

            return $result;
        }, 3);
    }
}
