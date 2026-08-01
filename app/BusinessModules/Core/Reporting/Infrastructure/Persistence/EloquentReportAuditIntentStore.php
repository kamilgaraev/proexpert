<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntent;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntentLease;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportAuditIntentRecord;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class EloquentReportAuditIntentStore implements ReportAuditIntentStore
{
    public function __construct(
        private readonly ReportExecutionTelemetry $telemetry,
        private readonly ReportExecutionRuntimeConfiguration $runtime,
    ) {}

    public function add(string $eventKey, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('report_audit_intent_transaction_required');
        }
        $timestamp = $this->timestamp($occurredAt);
        $inserted = ReportAuditIntentRecord::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'organization_id' => $context->scope->organizationId,
            'actor_id' => $context->actor->id,
            'subject' => CanonicalJson::encode($subject),
            'status' => 'pending',
            'attempt_count' => 0,
            'occurred_at' => $timestamp,
            'available_at' => $timestamp,
            'dispatch_reserved_until' => null,
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
            || (int) $existing->organization_id !== $context->scope->organizationId
            || (int) $existing->actor_id !== $context->actor->id
            || CanonicalJson::encode($existing->subject) !== CanonicalJson::encode($subject)
        ) {
            throw new LogicException('report_audit_event_key_conflict');
        }
    }

    public function dueIds(int $limit, DateTimeImmutable $now): array
    {
        $this->assertBatch($limit);

        return DB::transaction(function () use ($limit, $now): array {
            $timestamp = $this->timestamp($now);
            $ids = ReportAuditIntentRecord::query()
                ->where('status', 'pending')
                ->where('attempt_count', '<', $this->runtime->auditMaxAttempts)
                ->where('available_at', '<=', $timestamp)
                ->where(static function ($query) use ($timestamp): void {
                    $query->whereNull('dispatch_reserved_until')
                        ->orWhere('dispatch_reserved_until', '<=', $timestamp);
                })
                ->orderBy('available_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();
            if ($ids === []) {
                return [];
            }

            $reservedUntil = $this->timestamp($now->modify('+5 minutes'));
            $updated = ReportAuditIntentRecord::query()
                ->whereIn('id', $ids)
                ->where('status', 'pending')
                ->where('available_at', '<=', $timestamp)
                ->update([
                    'dispatch_reserved_until' => $reservedUntil,
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== count($ids)) {
                throw new LogicException('report_audit_dispatch_reservation_cas_failed');
            }

            return $ids;
        });
    }

    public function claim(string $intentId, string $leaseToken, DateTimeImmutable $now, DateTimeImmutable $leasedUntil): ?ReportAuditIntentLease
    {
        if (! Str::isUuid($leaseToken) || $leasedUntil <= $now) {
            throw new InvalidArgumentException('report_audit_lease_invalid');
        }

        return DB::transaction(function () use ($intentId, $leaseToken, $now, $leasedUntil): ?ReportAuditIntentLease {
            $record = ReportAuditIntentRecord::query()
                ->whereKey($intentId)
                ->where('status', 'pending')
                ->where('available_at', '<=', $this->timestamp($now))
                ->lockForUpdate()
                ->first();
            if (
                ! $record instanceof ReportAuditIntentRecord
                || (int) $record->attempt_count >= $this->runtime->auditMaxAttempts
            ) {
                return null;
            }
            $attempt = (int) $record->attempt_count + 1;
            $updated = ReportAuditIntentRecord::query()
                ->whereKey($intentId)
                ->where('status', 'pending')
                ->where('attempt_count', $record->attempt_count)
                ->update([
                    'status' => 'leased',
                    'attempt_count' => $attempt,
                    'lease_token' => $leaseToken,
                    'lease_expires_at' => $this->timestamp($leasedUntil),
                    'dispatch_reserved_until' => null,
                    'updated_at' => $this->timestamp($now),
                ]);

            return $updated === 1
                ? new ReportAuditIntentLease($intentId, $leaseToken, $leasedUntil, $attempt)
                : null;
        });
    }

    public function loadLeased(string $intentId, string $leaseToken): ReportAuditIntent
    {
        $record = ReportAuditIntentRecord::query()
            ->whereKey($intentId)
            ->where('status', 'leased')
            ->where('lease_token', $leaseToken)
            ->first();
        if (! $record instanceof ReportAuditIntentRecord) {
            throw new LogicException('report_audit_lease_not_found');
        }

        return new ReportAuditIntent(
            (string) $record->id,
            (string) $record->event_key,
            (string) $record->event_type,
            (int) $record->organization_id,
            (int) $record->actor_id,
            $record->subject,
            (int) $record->attempt_count,
            $this->instant($record->occurred_at),
            $this->instant($record->available_at),
        );
    }

    public function acknowledge(string $intentId, string $leaseToken, DateTimeImmutable $deliveredAt): void
    {
        ReportAuditIntentRecord::query()
            ->whereKey($intentId)
            ->where('status', 'leased')
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => 'delivered',
                'lease_token' => null,
                'lease_expires_at' => null,
                'dispatch_reserved_until' => null,
                'delivered_at' => $this->timestamp($deliveredAt),
                'last_error_code' => null,
                'updated_at' => $this->timestamp($deliveredAt),
            ]);
    }

    public function failDelivery(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void
    {
        DB::transaction(function () use ($intentId, $leaseToken, $errorCode, $occurredAt, $nextAttemptAt): void {
            $record = ReportAuditIntentRecord::query()
                ->whereKey($intentId)
                ->where('status', 'leased')
                ->where('lease_token', $leaseToken)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof ReportAuditIntentRecord) {
                return;
            }
            $terminal = (int) $record->attempt_count === $this->runtime->auditMaxAttempts;
            ReportAuditIntentRecord::query()
                ->whereKey($intentId)
                ->where('status', 'leased')
                ->where('lease_token', $leaseToken)
                ->update([
                    'status' => $terminal ? 'dead_letter' : 'pending',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'dispatch_reserved_until' => null,
                    'available_at' => $terminal ? $record->available_at : $this->timestamp($nextAttemptAt),
                    'dead_lettered_at' => $terminal ? $this->timestamp($occurredAt) : null,
                    'last_error_code' => $errorCode->value,
                    'updated_at' => $this->timestamp($occurredAt),
                ]);
            $this->telemetry->auditDeliveryFailure(
                $errorCode->value,
                $terminal ? 'dead_letter' : 'retry',
            );
        });
    }

    public function reclaimExpired(int $limit, DateTimeImmutable $occurredAt): int
    {
        $this->assertBatch($limit);

        return DB::transaction(function () use ($limit, $occurredAt): int {
            $records = ReportAuditIntentRecord::query()
                ->where('status', 'leased')
                ->where('lease_expires_at', '<=', $this->timestamp($occurredAt))
                ->orderBy('lease_expires_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();
            $reclaimed = 0;
            foreach ($records as $record) {
                $terminal = (int) $record->attempt_count === $this->runtime->auditMaxAttempts;
                $updated = ReportAuditIntentRecord::query()
                    ->whereKey($record->id)
                    ->where('status', 'leased')
                    ->where('lease_token', $record->lease_token)
                    ->where('lease_expires_at', '<=', $this->timestamp($occurredAt))
                    ->update([
                        'status' => $terminal ? 'dead_letter' : 'pending',
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'dispatch_reserved_until' => null,
                        'available_at' => $terminal ? $record->available_at : $this->timestamp($occurredAt),
                        'dead_lettered_at' => $terminal ? $this->timestamp($occurredAt) : null,
                        'last_error_code' => $terminal ? ReportErrorCode::REPORT_DEPENDENCY_FAILED->value : $record->last_error_code,
                        'updated_at' => $this->timestamp($occurredAt),
                    ]);
                $reclaimed += $updated;
                if ($updated === 1) {
                    $this->telemetry->auditDeliveryFailure(
                        ReportErrorCode::REPORT_DEPENDENCY_FAILED->value,
                        $terminal ? 'dead_letter' : 'retry',
                    );
                }
            }

            return $reclaimed;
        });
    }

    private function assertBatch(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('report_audit_batch_size_invalid');
        }
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        throw new LogicException('report_audit_intent_timestamp_invalid');
    }
}
