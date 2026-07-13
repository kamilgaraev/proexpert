<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class EloquentAdminFailureResolutionTransaction implements AdminFailureResolutionTransaction
{
    private const EVENT_TYPE = 'admin_failure_resolution';

    public function __construct(private ConnectionInterface $database) {}

    public function execute(AdminFailureResolutionCommand $command, callable $resolution): AdminFailureResolutionResult
    {
        return $this->database->transaction(function () use ($command, $resolution): AdminFailureResolutionResult {
            $now = now();
            $owner = $this->database->table('estimate_generation_admin_operations')->insertOrIgnore([
                'organization_id' => $command->organizationId,
                'operation' => AdminFailureResolutionCommand::OPERATION,
                'idempotency_key' => $command->idempotencyKey,
                'command_fingerprint' => $command->fingerprint(),
                'status' => 'pending',
                'result' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
            ]) === 1;
            $registry = $this->database->table('estimate_generation_admin_operations')
                ->select(['id', 'command_fingerprint', 'status', 'result'])
                ->where('organization_id', $command->organizationId)
                ->where('operation', AdminFailureResolutionCommand::OPERATION)
                ->where('idempotency_key', $command->idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($registry === null) {
                throw new \LogicException('Admin operation registry claim was lost.');
            }
            $claim = AdminFailureResolutionRegistryClaim::decide(
                $command->fingerprint(),
                (string) $registry->command_fingerprint,
                (string) $registry->status,
                $this->decodeResult($registry->result),
                $owner,
            );
            if ($claim->decision === 'conflict') {
                return AdminFailureResolutionResult::failure('estimate_generation.failure_resolution_idempotency_conflict');
            }
            if ($claim->decision === 'pending') {
                return AdminFailureResolutionResult::failure('estimate_generation.failure_resolution_in_progress');
            }
            if ($claim->decision === 'replay' && $claim->result !== null) {
                return AdminFailureResolutionResult::fromArray($claim->result);
            }

            $identity = $this->database->table('estimate_generation_failure_identities')
                ->select(['id', 'fingerprint', 'organization_id', 'project_id', 'session_id'])
                ->where('id', $command->failureId)
                ->where('organization_id', $command->organizationId)
                ->where('project_id', $command->projectId)
                ->where('session_id', $command->sessionId)
                ->lockForUpdate()
                ->first();
            if ($identity === null) {
                $result = AdminFailureResolutionResult::failure('estimate_generation.admin_operation_not_found');
            } else {
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
            }

            $this->database->table('estimate_generation_audit_events')->insert([
                'session_id' => $command->sessionId,
                'user_id' => null,
                'event_type' => self::EVENT_TYPE,
                'payload' => json_encode([
                    'actor_type' => 'system_admin',
                    'actor_id' => $command->actorId,
                    'organization_id' => $command->organizationId,
                    'project_id' => $command->projectId,
                    'failure_id' => $command->failureId,
                    'occurrence_sequence' => $command->expectedOccurrenceSequence,
                    'idempotency_key' => $command->idempotencyKey,
                    'command_fingerprint' => $command->fingerprint(),
                    'result' => $result->toArray(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $completed = $this->database->table('estimate_generation_admin_operations')
                ->where('id', (int) $registry->id)
                ->where('command_fingerprint', $command->fingerprint())
                ->where('status', 'pending')
                ->update([
                    'status' => 'completed',
                    'result' => json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                    'completed_at' => $now,
                ]);
            if ($completed !== 1) {
                throw new \LogicException('Admin operation registry completion was lost.');
            }

            return $result;
        }, 3);
    }

    /** @return array<string, mixed>|null */
    private function decodeResult(mixed $result): ?array
    {
        if (is_array($result)) {
            return $result;
        }
        if (! is_string($result)) {
            return null;
        }
        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }
}
