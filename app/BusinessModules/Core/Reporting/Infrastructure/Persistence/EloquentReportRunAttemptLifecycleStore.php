<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportAuditIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class EloquentReportRunAttemptLifecycleStore implements ReportRunAttemptLifecycleStore
{
    public function claimOrRenew(
        string $runId,
        string $envelopeUuid,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): bool {
        $this->assertAttemptInput($runId, $envelopeUuid);
        if ($leaseExpiresAt <= $occurredAt) {
            throw new InvalidArgumentException('report_run_attempt_lease_invalid');
        }

        return DB::transaction(function () use ($runId, $envelopeUuid, $leaseExpiresAt, $occurredAt): bool {
            $record = ReportRunRecord::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $record instanceof ReportRunRecord) {
                return false;
            }
            if ($record->status === ReportRunStatus::QUEUED->value) {
                $this->appendAudit(
                    $record,
                    "reports:run:{$runId}:materializing:{$envelopeUuid}",
                    'report.run.materializing',
                    [
                        'run_id' => $runId,
                        'report_code' => (string) $record->report_code,
                        'status' => ReportRunStatus::MATERIALIZING->value,
                        'definition_hash' => (string) $record->definition_hash,
                        'query_hash' => (string) $record->query_hash,
                    ],
                    $occurredAt,
                );
                $updated = ReportRunRecord::query()
                    ->whereKey($runId)
                    ->where('status', ReportRunStatus::QUEUED->value)
                    ->update([
                        'status' => ReportRunStatus::MATERIALIZING->value,
                        'execution_lease_token' => $envelopeUuid,
                        'execution_lease_expires_at' => $this->timestamp($leaseExpiresAt),
                        'execution_heartbeat_at' => $this->timestamp($occurredAt),
                        'started_at' => $this->timestamp($occurredAt),
                        'updated_at' => $this->timestamp($occurredAt),
                    ]);
                if ($updated !== 1) {
                    throw new LogicException('report_run_attempt_claim_cas_failed');
                }

                return true;
            }
            $currentExpiry = $this->instant($record->execution_lease_expires_at);
            $currentHeartbeat = $this->instant($record->execution_heartbeat_at);
            $currentUpdatedAt = $this->instant($record->updated_at);
            if (
                $record->status !== ReportRunStatus::MATERIALIZING->value
                || ! is_string($record->execution_lease_token)
                || ! hash_equals($record->execution_lease_token, $envelopeUuid)
                || ! $currentExpiry instanceof DateTimeImmutable
                || ! $currentHeartbeat instanceof DateTimeImmutable
                || ! $currentUpdatedAt instanceof DateTimeImmutable
                || $currentExpiry <= $occurredAt
                || $leaseExpiresAt < $currentExpiry
                || $occurredAt < $currentHeartbeat
                || $occurredAt < $currentUpdatedAt
            ) {
                return false;
            }
            $updated = ReportRunRecord::query()
                ->whereKey($runId)
                ->where('status', ReportRunStatus::MATERIALIZING->value)
                ->where('execution_lease_token', $envelopeUuid)
                ->where('execution_lease_expires_at', '>', $this->timestamp($occurredAt))
                ->update([
                    'execution_lease_expires_at' => $this->timestamp($leaseExpiresAt),
                    'execution_heartbeat_at' => $this->timestamp($occurredAt),
                    'updated_at' => $this->timestamp($occurredAt),
                ]);

            return $updated === 1;
        });
    }

    public function failLeased(
        string $runId,
        string $envelopeUuid,
        ReportErrorCode $errorCode,
        DateTimeImmutable $occurredAt,
    ): bool {
        $this->assertAttemptInput($runId, $envelopeUuid);
        ReportErrorCatalog::descriptor($errorCode);

        return DB::transaction(function () use ($runId, $envelopeUuid, $errorCode, $occurredAt): bool {
            $record = ReportRunRecord::query()
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();
            $leaseExpiresAt = $record instanceof ReportRunRecord
                ? $this->instant($record->execution_lease_expires_at)
                : null;
            if (
                ! $record instanceof ReportRunRecord
                || $record->status !== ReportRunStatus::MATERIALIZING->value
                || ! is_string($record->execution_lease_token)
                || ! hash_equals($record->execution_lease_token, $envelopeUuid)
                || ! $leaseExpiresAt instanceof DateTimeImmutable
                || $leaseExpiresAt <= $occurredAt
            ) {
                return false;
            }

            $this->appendAudit(
                $record,
                "reports:run:{$record->id}:failed:{$errorCode->value}",
                'report.run.failed',
                [
                    'run_id' => (string) $record->id,
                    'report_code' => (string) $record->report_code,
                    'status' => ReportRunStatus::FAILED->value,
                    'definition_hash' => (string) $record->definition_hash,
                    'query_hash' => (string) $record->query_hash,
                    'error_code' => $errorCode->value,
                ],
                $occurredAt,
            );
            $updated = ReportRunRecord::query()
                ->whereKey($record->id)
                ->where('status', ReportRunStatus::MATERIALIZING->value)
                ->where('execution_lease_token', $envelopeUuid)
                ->where('execution_lease_expires_at', '>', $this->timestamp($occurredAt))
                ->update([
                    'status' => ReportRunStatus::FAILED->value,
                    'error_code' => $errorCode->value,
                    'execution_lease_token' => null,
                    'execution_lease_expires_at' => null,
                    'execution_heartbeat_at' => null,
                    'failed_at' => $this->timestamp($occurredAt),
                    'updated_at' => $this->timestamp($occurredAt),
                ]);
            if ($updated !== 1) {
                throw new LogicException('report_run_attempt_terminal_cas_failed');
            }

            return true;
        });
    }

    private function appendAudit(
        ReportRunRecord $record,
        string $eventKey,
        string $eventType,
        array $subject,
        DateTimeImmutable $occurredAt,
    ): void {
        $timestamp = $this->timestamp($occurredAt);
        $inserted = ReportAuditIntentRecord::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'organization_id' => (int) $record->organization_id,
            'actor_id' => (int) $record->requester_actor_id,
            'subject' => CanonicalJson::encode($subject),
            'status' => 'pending',
            'attempt_count' => 0,
            'occurred_at' => $timestamp,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        if ($inserted === 1) {
            return;
        }
        $existing = ReportAuditIntentRecord::query()->where('event_key', $eventKey)->lockForUpdate()->first();
        if (
            ! $existing instanceof ReportAuditIntentRecord
            || $existing->event_type !== $eventType
            || (int) $existing->organization_id !== (int) $record->organization_id
            || (int) $existing->actor_id !== (int) $record->requester_actor_id
            || CanonicalJson::encode($existing->subject) !== CanonicalJson::encode($subject)
        ) {
            throw new LogicException('report_audit_event_key_conflict');
        }
    }

    private function assertAttemptInput(string $runId, string $envelopeUuid): void
    {
        if (
            preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $runId) !== 1
            || ! Str::isUuid($envelopeUuid)
            || $envelopeUuid !== strtolower($envelopeUuid)
        ) {
            throw new InvalidArgumentException('report_run_attempt_lease_invalid');
        }
    }

    private function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }
}
