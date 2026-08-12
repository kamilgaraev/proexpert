<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EstimateInterpretationAttemptRepository
{
    /** @return array<string, mixed> */
    public function claim(int $organizationId, int $projectId, int $sessionId, string $key, string $fingerprint, string $owner, int $leaseSeconds = 60): array
    {
        $now = now();
        DB::table('estimate_interpretation_attempts')->insertOrIgnore([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'session_id' => $sessionId,
            'idempotency_key' => $key,
            'request_fingerprint' => $fingerprint,
            'state' => 'pre_wire',
            'owner_uuid' => $owner,
            'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::transaction(function () use ($organizationId, $projectId, $sessionId, $key, $fingerprint, $owner, $leaseSeconds, $now): array {
            $query = DB::table('estimate_interpretation_attempts')
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->where('idempotency_key', $key);
            $row = $query->lockForUpdate()->first();
            if ($row === null || ! hash_equals((string) $row->request_fingerprint, $fingerprint)) {
                throw new RuntimeException('estimate_generation.proposal_idempotency_collision');
            }
            $state = (string) $row->state;
            if ($state === 'completed') {
                return ['action' => 'replay', 'result' => $this->decode($row->result_payload)];
            }
            if (in_array($state, ['ambiguous', 'failed'], true)) {
                return ['action' => $state, 'interpretation' => $this->decode($row->interpretation_payload)];
            }
            $leaseExpired = $row->lease_expires_at !== null && $now->greaterThanOrEqualTo($row->lease_expires_at);
            if ($state === 'response_received') {
                if ($row->owner_uuid === null || $leaseExpired) {
                    $query->update(['owner_uuid' => $owner, 'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds), 'updated_at' => $now]);

                    return ['action' => 'resume', 'interpretation' => $this->decode($row->interpretation_payload)];
                }

                return ['action' => 'busy'];
            }
            if ($state === 'pre_wire' && $leaseExpired) {
                $query->update(['owner_uuid' => $owner, 'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds), 'updated_at' => $now]);

                return ['action' => 'owned'];
            }
            if ($state === 'wire_started' && $leaseExpired) {
                $query->update(['state' => 'ambiguous', 'owner_uuid' => null, 'lease_expires_at' => null, 'failure_code' => 'wire_outcome_unknown', 'updated_at' => $now]);

                return ['action' => 'ambiguous'];
            }
            if ((string) $row->owner_uuid === $owner) {
                return ['action' => 'owned'];
            }

            return ['action' => 'busy'];
        }, 3);
    }

    public function markWireStarted(int $organizationId, int $projectId, int $sessionId, string $key, string $fingerprint, string $owner): void
    {
        $updated = DB::table('estimate_interpretation_attempts')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)->where('idempotency_key', $key)
            ->where('request_fingerprint', $fingerprint)->where('state', 'pre_wire')->where('owner_uuid', $owner)
            ->where('lease_expires_at', '>', now())->update(['state' => 'wire_started', 'wire_started_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new RuntimeException('estimate_generation.interpretation_attempt_lost');
        }
    }

    /** @param array<string, mixed> $payload */
    public function storeResponse(int $organizationId, int $projectId, int $sessionId, string $key, string $fingerprint, string $owner, array $payload): void
    {
        $updated = DB::table('estimate_interpretation_attempts')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)->where('idempotency_key', $key)
            ->where('request_fingerprint', $fingerprint)->where('state', 'wire_started')->where('owner_uuid', $owner)
            ->update(['state' => 'response_received', 'interpretation_payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'lease_expires_at' => now()->addSeconds(60), 'response_received_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new RuntimeException('estimate_generation.interpretation_response_collision');
        }
    }

    /** @param array<string, mixed> $result */
    public function complete(int $organizationId, int $projectId, int $sessionId, string $key, string $fingerprint, string $owner, array $result): void
    {
        $updated = DB::table('estimate_interpretation_attempts')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)->where('idempotency_key', $key)
            ->where('request_fingerprint', $fingerprint)->where('state', 'response_received')->where('owner_uuid', $owner)
            ->update(['state' => 'completed', 'result_payload' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'owner_uuid' => null, 'lease_expires_at' => null, 'completed_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new RuntimeException('estimate_generation.interpretation_completion_collision');
        }
    }

    public function markAmbiguous(int $organizationId, int $projectId, int $sessionId, string $key, string $fingerprint, string $owner): void
    {
        DB::table('estimate_interpretation_attempts')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)->where('idempotency_key', $key)
            ->where('request_fingerprint', $fingerprint)->where('state', 'wire_started')->where('owner_uuid', $owner)
            ->update(['state' => 'ambiguous', 'failure_code' => 'wire_outcome_unknown', 'owner_uuid' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }
        $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }
}
