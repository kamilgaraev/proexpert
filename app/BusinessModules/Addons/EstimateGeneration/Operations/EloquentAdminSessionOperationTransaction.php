<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFailure;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\ConnectionInterface;

final readonly class EloquentAdminSessionOperationTransaction implements AdminSessionOperationTransaction
{
    private const EVENT_TYPE = 'admin_session_operation';

    public function __construct(private ConnectionInterface $database) {}

    public function execute(AdminSessionOperationCommand $command, callable $operation): AdminSessionOperationResult
    {
        return $this->database->transaction(function () use ($command, $operation): AdminSessionOperationResult {
            $session = EstimateGenerationSession::query()
                ->select(['id', 'organization_id', 'project_id', 'status', 'resume_status', 'state_version'])
                ->whereKey($command->sessionId)
                ->where('organization_id', $command->organizationId)
                ->where('project_id', $command->projectId)
                ->lockForUpdate()
                ->first();
            if (! $session instanceof EstimateGenerationSession) {
                return AdminSessionOperationResult::failure('estimate_generation.admin_operation_not_found');
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
                return AdminSessionOperationResult::fromArray($replay->payload['result'], true);
            }

            $snapshot = new AdminSessionOperationSnapshot(
                (int) $session->getKey(),
                (int) $session->organization_id,
                (int) $session->project_id,
                (int) $session->state_version,
                $this->enumValue($session->status),
                $session->resume_status === null ? null : $this->enumValue($session->resume_status),
                EstimateGenerationFailure::query()
                    ->where('session_id', $command->sessionId)
                    ->where('organization_id', $command->organizationId)
                    ->where('project_id', $command->projectId)
                    ->where('category', 'recoverable')
                    ->whereNull('resolved_at')
                    ->exists(),
            );
            $result = $snapshot->stateVersion !== $command->expectedStateVersion
                ? AdminSessionOperationResult::failure('estimate_generation.admin_operation_state_conflict')
                : $operation($snapshot);

            EstimateGenerationAuditEvent::query()->create([
                'session_id' => $command->sessionId,
                'user_id' => null,
                'event_type' => self::EVENT_TYPE,
                'payload' => [
                    'actor_type' => 'system_admin',
                    'actor_id' => $command->actorId,
                    'organization_id' => $command->organizationId,
                    'project_id' => $command->projectId,
                    'operation' => $command->operation->value,
                    'idempotency_key' => $command->idempotencyKey,
                    'expected_state_version' => $command->expectedStateVersion,
                    'result' => $result->toArray(),
                ],
            ]);

            return $result;
        });
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
