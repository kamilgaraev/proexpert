<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntent;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchLease;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchTopic;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportDispatchIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class EloquentReportDispatchIntentStore implements ReportDispatchIntentStore
{
    public function __construct(private readonly ReportTransitionAudit $audit) {}

    public function addRunIntent(string $runId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void
    {
        $this->add($runId, $organizationId, $eventKey, ReportDispatchAggregate::RUN, ReportDispatchTopic::MATERIALIZE_RUN, $occurredAt);
    }

    public function addExportIntent(string $exportId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void
    {
        $this->add($exportId, $organizationId, $eventKey, ReportDispatchAggregate::EXPORT, ReportDispatchTopic::GENERATE_EXPORT, $occurredAt);
    }

    public function claimDue(int $limit, DateTimeImmutable $now, DateTimeImmutable $leasedUntil, string $leaseToken): array
    {
        $this->assertBatch($limit);
        if (!Str::isUuid($leaseToken) || $leasedUntil <= $now) {
            throw new InvalidArgumentException('report_dispatch_lease_invalid');
        }

        return DB::transaction(function () use ($limit, $now, $leasedUntil, $leaseToken): array {
            $records = ReportDispatchIntentRecord::query()
                ->where('status', 'pending')
                ->where('attempt_count', '<', 12)
                ->where('available_at', '<=', $this->timestamp($now))
                ->orderBy('available_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();
            $leases = [];
            foreach ($records as $record) {
                $attempt = (int) $record->attempt_count + 1;
                $updated = ReportDispatchIntentRecord::query()
                    ->whereKey($record->id)
                    ->where('status', 'pending')
                    ->where('attempt_count', $record->attempt_count)
                    ->update([
                        'status' => 'leased',
                        'attempt_count' => $attempt,
                        'lease_token' => $leaseToken,
                        'lease_expires_at' => $this->timestamp($leasedUntil),
                        'updated_at' => $this->timestamp($now),
                    ]);
                if ($updated !== 1) {
                    continue;
                }
                $intent = $this->intent($record, $attempt);
                $leases[] = new ReportDispatchLease($intent, $leaseToken, $leasedUntil);
            }

            return $leases;
        });
    }

    public function markPublished(string $intentId, string $leaseToken, DateTimeImmutable $occurredAt): void
    {
        ReportDispatchIntentRecord::query()
            ->whereKey($intentId)
            ->where('status', 'leased')
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => 'published',
                'lease_token' => null,
                'lease_expires_at' => null,
                'published_at' => $this->timestamp($occurredAt),
                'last_error_code' => null,
                'updated_at' => $this->timestamp($occurredAt),
            ]);
    }

    public function markPublicationFailed(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void
    {
        DB::transaction(function () use ($intentId, $leaseToken, $errorCode, $occurredAt, $nextAttemptAt): void {
            $record = ReportDispatchIntentRecord::query()
                ->whereKey($intentId)
                ->where('status', 'leased')
                ->where('lease_token', $leaseToken)
                ->lockForUpdate()
                ->first();
            if (!$record instanceof ReportDispatchIntentRecord) {
                return;
            }

            if ((int) $record->attempt_count === 12) {
                ReportDispatchIntentRecord::query()
                    ->whereKey($record->id)
                    ->where('status', 'leased')
                    ->where('lease_token', $leaseToken)
                    ->where('attempt_count', 12)
                    ->update([
                        'status' => 'dead_letter',
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'dead_lettered_at' => $this->timestamp($occurredAt),
                        'last_error_code' => $errorCode->value,
                        'updated_at' => $this->timestamp($occurredAt),
                    ]);
                if (
                    $record->aggregate_type === ReportDispatchAggregate::RUN->value
                    && $record->topic === ReportDispatchTopic::MATERIALIZE_RUN->value
                ) {
                    $this->failQueuedRun($record, $errorCode, $occurredAt);
                }

                return;
            }

            ReportDispatchIntentRecord::query()
                ->whereKey($record->id)
                ->where('status', 'leased')
                ->where('lease_token', $leaseToken)
                ->update([
                    'status' => 'pending',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'available_at' => $this->timestamp($nextAttemptAt),
                    'last_error_code' => $errorCode->value,
                    'updated_at' => $this->timestamp($occurredAt),
                ]);
        });
    }

    public function reclaimExpiredLeases(int $limit, DateTimeImmutable $occurredAt): int
    {
        $this->assertBatch($limit);

        return DB::transaction(function () use ($limit, $occurredAt): int {
            $records = ReportDispatchIntentRecord::query()
                ->where('status', 'leased')
                ->where('lease_expires_at', '<=', $this->timestamp($occurredAt))
                ->orderBy('lease_expires_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();
            $reclaimed = 0;
            foreach ($records as $record) {
                $terminal = (int) $record->attempt_count === 12;
                $updated = ReportDispatchIntentRecord::query()
                    ->whereKey($record->id)
                    ->where('status', 'leased')
                    ->where('lease_token', $record->lease_token)
                    ->where('lease_expires_at', '<=', $this->timestamp($occurredAt))
                    ->update([
                        'status' => $terminal ? 'dead_letter' : 'pending',
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'available_at' => $terminal ? $record->available_at : $this->timestamp($occurredAt),
                        'dead_lettered_at' => $terminal ? $this->timestamp($occurredAt) : null,
                        'last_error_code' => $terminal ? ReportErrorCode::REPORT_DEPENDENCY_FAILED->value : $record->last_error_code,
                        'updated_at' => $this->timestamp($occurredAt),
                    ]);
                if ($updated !== 1) {
                    continue;
                }
                $reclaimed++;
                if (
                    $terminal
                    && $record->aggregate_type === ReportDispatchAggregate::RUN->value
                    && $record->topic === ReportDispatchTopic::MATERIALIZE_RUN->value
                ) {
                    $this->failQueuedRun($record, ReportErrorCode::REPORT_DEPENDENCY_FAILED, $occurredAt);
                }
            }

            return $reclaimed;
        });
    }

    private function failQueuedRun(ReportDispatchIntentRecord $intent, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): void
    {
        $run = ReportRunRecord::query()
            ->whereKey($intent->aggregate_id)
            ->where('organization_id', $intent->organization_id)
            ->lockForUpdate()
            ->first();
        if (!$run instanceof ReportRunRecord || $run->status !== ReportRunStatus::QUEUED->value) {
            return;
        }
        $updated = ReportRunRecord::query()
            ->whereKey($run->id)
            ->where('organization_id', $run->organization_id)
            ->where('status', ReportRunStatus::QUEUED->value)
            ->update([
                'status' => ReportRunStatus::FAILED->value,
                'error_code' => $errorCode->value,
                'failed_at' => $this->timestamp($occurredAt),
                'updated_at' => $this->timestamp($occurredAt),
            ]);
        if ($updated !== 1) {
            return;
        }
        $this->audit->append(
            "reports:run:{$run->id}:failed:{$errorCode->value}",
            'report.run.failed',
            $this->context($run),
            [
                'run_id' => (string) $run->id,
                'report_code' => (string) $run->report_code,
                'status' => ReportRunStatus::FAILED->value,
                'definition_hash' => (string) $run->definition_hash,
                'query_hash' => (string) $run->query_hash,
                'error_code' => $errorCode->value,
            ],
            $occurredAt,
        );
    }

    private function context(ReportRunRecord $run): ReportExecutionContext
    {
        $timezone = new DateTimeZone((string) $run->scope_timezone);
        $holdingOrganizationIds = array_map('intval', $run->scope_holding_organization_ids);
        $projectIds = array_map('intval', $run->scope_project_ids);
        $resourceIds = array_map('intval', $run->scope_resource_ids);

        return new ReportExecutionContext(
            new ReportActor((int) $run->requester_actor_id, 'active', []),
            new ReportScope((int) $run->organization_id, $holdingOrganizationIds, $projectIds, $resourceIds, $timezone),
            new ReportVisibility(true, false, false, false, false, false, false),
            new AuthorizationDecisionContext(
                'queue',
                (int) $run->organization_id,
                $holdingOrganizationIds,
                $projectIds,
                $resourceIds,
                $timezone,
                "reports:run:{$run->id}:dispatch-failure",
                null,
            ),
        );
    }

    private function add(string $aggregateId, int $organizationId, string $eventKey, ReportDispatchAggregate $aggregate, ReportDispatchTopic $topic, DateTimeImmutable $occurredAt): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('report_dispatch_intent_transaction_required');
        }
        if (!$this->isUlid($aggregateId) || $organizationId < 1 || $eventKey === '' || strlen($eventKey) > 512) {
            throw new InvalidArgumentException('report_dispatch_intent_invalid');
        }

        $id = (string) Str::ulid();
        $timestamp = $this->timestamp($occurredAt);
        $inserted = ReportDispatchIntentRecord::query()->insertOrIgnore([
            'id' => $id,
            'event_key' => $eventKey,
            'organization_id' => $organizationId,
            'aggregate_type' => $aggregate->value,
            'aggregate_id' => $aggregateId,
            'topic' => $topic->value,
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
        $existing = ReportDispatchIntentRecord::query()->where('event_key', $eventKey)->lockForUpdate()->first();
        if (
            !$existing instanceof ReportDispatchIntentRecord
            || (int) $existing->organization_id !== $organizationId
            || $existing->aggregate_type !== $aggregate->value
            || $existing->aggregate_id !== $aggregateId
            || $existing->topic !== $topic->value
        ) {
            throw new LogicException('report_dispatch_event_key_conflict');
        }
    }

    private function intent(ReportDispatchIntentRecord $record, int $attempt): ReportDispatchIntent
    {
        return new ReportDispatchIntent(
            (string) $record->id,
            (string) $record->event_key,
            (int) $record->organization_id,
            ReportDispatchAggregate::from((string) $record->aggregate_type),
            (string) $record->aggregate_id,
            ReportDispatchTopic::from((string) $record->topic),
            $attempt,
            $this->instant($record->occurred_at),
            $this->instant($record->available_at),
        );
    }

    private function assertBatch(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('report_dispatch_batch_size_invalid');
        }
    }

    private function isUlid(string $value): bool
    {
        return preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/D', $value) === 1;
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

        throw new LogicException('report_dispatch_intent_timestamp_invalid');
    }
}
