<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use JsonException;

final readonly class EloquentAiRoleRunRepository implements AiRoleRunRepository
{
    private const TABLE = 'estimate_generation_ai_role_runs';

    public function __construct(
        private Connection $database,
        private int $leaseSeconds,
    ) {
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('ai_role_run_lease_invalid');
        }
    }

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        $this->assertUuid($ownerUuid);
        $now = new DateTimeImmutable;
        $leaseExpiresAt = $now->modify('+'.$this->leaseSeconds.' seconds');
        $identity = $input->identityFingerprint();
        $this->database->table(self::TABLE)->insertOrIgnore([
            ...$this->identityColumns($input),
            'status' => 'running',
            'identity_fingerprint' => $identity,
            'owner_uuid' => $ownerUuid,
            'lease_expires_at' => $leaseExpiresAt,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->database->transaction(function () use ($input, $ownerUuid, $now, $leaseExpiresAt, $identity): AiRoleRunClaim {
            $query = $this->database->table(self::TABLE)->where('identity_fingerprint', $identity);
            $row = $query->lockForUpdate()->first();
            if ($row === null || ! $this->matches($row, $input)) {
                throw new UsageInvariantViolation('AI role run identity collision.');
            }
            $status = (string) $row->status;
            if ($status === 'completed') {
                return new AiRoleRunClaim((int) $row->id, 'replay', result: $this->result($row));
            }
            if ($status === 'ambiguous') {
                return new AiRoleRunClaim((int) $row->id, 'ambiguous', failureCode: $this->nullableString($row->failure_code));
            }
            if ($status === 'failed') {
                $query->update([
                    'status' => 'running',
                    'owner_uuid' => $ownerUuid,
                    'lease_expires_at' => $leaseExpiresAt,
                    'physical_attempt_id' => null,
                    'failure_code' => null,
                    'started_at' => $now,
                    'failed_at' => null,
                    'updated_at' => $now,
                ]);

                return new AiRoleRunClaim((int) $row->id, 'owned', $ownerUuid);
            }
            $currentOwner = $this->nullableString($row->owner_uuid);
            $lease = $row->lease_expires_at === null ? null : new DateTimeImmutable((string) $row->lease_expires_at);
            if ($currentOwner === $ownerUuid) {
                return new AiRoleRunClaim((int) $row->id, 'owned', $ownerUuid);
            }
            if ($lease !== null && $lease > $now) {
                return new AiRoleRunClaim((int) $row->id, 'busy', $currentOwner);
            }
            if ($row->physical_attempt_id !== null) {
                $query->update([
                    'status' => 'ambiguous',
                    'owner_uuid' => null,
                    'lease_expires_at' => null,
                    'failure_code' => 'physical_attempt_outcome_unknown',
                    'failed_at' => $now,
                    'updated_at' => $now,
                ]);

                return new AiRoleRunClaim((int) $row->id, 'ambiguous', failureCode: 'physical_attempt_outcome_unknown');
            }
            $query->update([
                'owner_uuid' => $ownerUuid,
                'lease_expires_at' => $leaseExpiresAt,
                'started_at' => $now,
                'updated_at' => $now,
            ]);

            return new AiRoleRunClaim((int) $row->id, 'owned', $ownerUuid);
        }, 3);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void
    {
        $this->assertUuid($ownerUuid);
        $this->assertUuid($physicalAttemptId);
        $now = new DateTimeImmutable;
        $updated = $this->database->table(self::TABLE)
            ->where('id', $runId)
            ->where('status', 'running')
            ->where('owner_uuid', $ownerUuid)
            ->where('lease_expires_at', '>', $now)
            ->whereNull('physical_attempt_id')
            ->update([
                'physical_attempt_id' => $physicalAttemptId,
                'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            $existing = $this->database->table(self::TABLE)->where('id', $runId)->value('physical_attempt_id');
            if (! is_string($existing) || ! hash_equals($existing, $physicalAttemptId)) {
                throw new UsageInvariantViolation('AI role run physical attempt collision.');
            }
        }
    }

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->assertUuid($ownerUuid);
        $now = new DateTimeImmutable;
        $encoded = json_encode($result->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $query = $this->database->table(self::TABLE)
            ->where('id', $runId)
            ->where('status', 'running')
            ->where('owner_uuid', $ownerUuid)
            ->where('lease_expires_at', '>', $now);
        if ($result->physicalAttemptId === null) {
            $query->whereNull('physical_attempt_id');
        } else {
            $query->where('physical_attempt_id', $result->physicalAttemptId);
        }
        $updated = $query->update([
            'status' => 'completed',
            'result_payload' => $encoded,
            'owner_uuid' => null,
            'lease_expires_at' => null,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
        if ($updated === 1) {
            return;
        }
        $row = $this->database->table(self::TABLE)->where('id', $runId)->first();
        if ($row === null || (string) $row->status !== 'completed'
            || ! hash_equals($this->payloadFingerprint($this->decode((string) $row->result_payload)), $this->payloadFingerprint($result->payload))) {
            throw new UsageInvariantViolation('AI role run completion collision.');
        }
    }

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void
    {
        $this->assertUuid($ownerUuid);
        $now = new DateTimeImmutable;
        $query = $this->database->table(self::TABLE)
            ->where('id', $runId)
            ->where('status', 'running')
            ->where('owner_uuid', $ownerUuid)
            ->where('lease_expires_at', '>', $now);
        if ($failure->physicalAttemptId !== null) {
            $query->where('physical_attempt_id', $failure->physicalAttemptId);
        }
        $updated = $query->update([
            'status' => $failure->ambiguous ? 'ambiguous' : 'failed',
            'failure_code' => $failure->code,
            'owner_uuid' => null,
            'lease_expires_at' => null,
            'failed_at' => $now,
            'updated_at' => $now,
        ]);
        if ($updated !== 1) {
            throw new UsageInvariantViolation('AI role run failure collision.');
        }
    }

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim
    {
        $row = $this->database->table(self::TABLE)
            ->where('identity_fingerprint', $input->identityFingerprint())
            ->where('organization_id', $input->organizationId)
            ->where('project_id', $input->projectId)
            ->where('session_id', $input->sessionId)
            ->first();
        if ($row === null || ! $this->matches($row, $input)) {
            return null;
        }
        $status = (string) $row->status;

        return new AiRoleRunClaim(
            (int) $row->id,
            match ($status) {
                'completed' => 'replay',
                'ambiguous' => 'ambiguous',
                'failed' => 'failed',
                default => 'busy',
            },
            $this->nullableString($row->owner_uuid),
            $status === 'completed' ? $this->result($row) : null,
            $this->nullableString($row->failure_code),
        );
    }

    /** @return array<string, mixed> */
    private function identityColumns(AiRoleRunInput $input): array
    {
        return [
            'organization_id' => $input->organizationId,
            'project_id' => $input->projectId,
            'session_id' => $input->sessionId,
            'document_id' => $input->documentId,
            'page_id' => $input->pageId,
            'subject_type' => $input->subjectType,
            'subject_id' => $input->subjectId,
            'subject_version' => $input->subjectVersion,
            'role' => $input->role->value,
            'model' => $input->model,
            'prompt_contract_version' => $input->promptContractVersion,
            'input_fingerprint' => $input->inputFingerprint,
        ];
    }

    private function matches(object $row, AiRoleRunInput $input): bool
    {
        foreach ($this->identityColumns($input) as $column => $value) {
            $actual = $row->{$column};
            if ($value === null ? $actual !== null : ! hash_equals((string) $actual, (string) $value)) {
                return false;
            }
        }

        return true;
    }

    private function result(object $row): AiRoleRunResult
    {
        return new AiRoleRunResult(
            $this->decode((string) $row->result_payload),
            $this->nullableString($row->physical_attempt_id),
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UsageInvariantViolation('AI role run payload is invalid.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new UsageInvariantViolation('AI role run payload is invalid.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function payloadFingerprint(array $payload): string
    {
        $sort = static function (array &$value) use (&$sort): void {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $sort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertUuid(string $value): void
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('ai_role_run_uuid_invalid');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
