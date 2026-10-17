<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CancelOwnedReportWork;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportAuditIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final readonly class EloquentCancelOwnedReportWork implements CancelOwnedReportWork
{
    public function __construct(private ReportExecutionClock $clock) {}

    public function handle(int $organizationId, int $requesterActorId, ?string $runId, IdempotencyKey $runKey,
        ?string $exportId = null, ?IdempotencyKey $exportKey = null): void
    {
        if ($organizationId < 1 || $requesterActorId < 1 || ($runId !== null && ! Str::isUlid($runId))
            || ($exportId !== null && ($exportKey === null || ! Str::isUlid($exportId)))) {
            throw new InvalidArgumentException('report_owned_cleanup_invalid');
        }
        DB::transaction(function () use ($organizationId, $requesterActorId, $runId, $runKey, $exportId, $exportKey): void {
            $run = ReportRunRecord::query()->when($runId !== null, static fn ($query) => $query->whereKey($runId))->where('organization_id', $organizationId)
                ->where('requester_actor_id', $requesterActorId)->where('idempotency_key_hash', $runKey->hash)->lockForUpdate()->first();
            if (! $run instanceof ReportRunRecord) {
                if ($runId !== null || $exportId !== null) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
                }

                return;
            }
            $export = null;
            if ($exportKey !== null) {
                $export = ReportExportRecord::query()->when($exportId !== null, static fn ($query) => $query->whereKey($exportId))
                    ->where('run_id', $run->id)->where('organization_id', $organizationId)
                    ->where('requester_actor_id', $requesterActorId)->where('idempotency_key_hash', $exportKey->hash)->lockForUpdate()->first();
                if ($exportId !== null && ! $export instanceof ReportExportRecord) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
                }
            }
            if ($export !== null && in_array($export->status, ['queued', 'running', 'uploading'], true)) {
                $this->cancel($export, 'export');
            }
            if (in_array($run->status, ['queued', 'materializing'], true)) {
                $this->cancel($run, 'run');
            }
        }, 3);
    }

    private function cancel(ReportRunRecord|ReportExportRecord $record, string $kind): void
    {
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $eventKey = "reports:{$kind}:{$record->id}:cancelled:owned-cleanup";
        $eventType = "report.{$kind}.cancelled";
        $subject = [$kind.'_id' => (string) $record->id, 'report_code' => (string) $record->report_code,
            'status' => 'cancelled'];
        if ($record instanceof ReportExportRecord) {
            $subject += ['run_id' => (string) $record->run_id, 'format' => (string) $record->format];
        } else {
            $subject += ['definition_hash' => (string) $record->definition_hash, 'query_hash' => (string) $record->query_hash];
        }
        $inserted = ReportAuditIntentRecord::query()->insertOrIgnore(['id' => (string) Str::ulid(), 'event_key' => $eventKey,
            'event_type' => $eventType, 'organization_id' => (int) $record->organization_id, 'actor_id' => (int) $record->requester_actor_id,
            'subject' => CanonicalJson::encode($subject), 'status' => 'pending', 'attempt_count' => 0,
            'occurred_at' => $timestamp, 'available_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        if ($inserted !== 1) {
            $existing = ReportAuditIntentRecord::query()->where('event_key', $eventKey)->lockForUpdate()->first();
            if (! $existing instanceof ReportAuditIntentRecord || $existing->event_type !== $eventType
                || (int) $existing->organization_id !== (int) $record->organization_id
                || (int) $existing->actor_id !== (int) $record->requester_actor_id
                || CanonicalJson::encode($existing->subject) !== CanonicalJson::encode($subject)) {
                throw new LogicException('report_owned_cleanup_audit_conflict');
            }
        }
        $updated = $record->newQuery()->whereKey($record->id)->where('organization_id', $record->organization_id)
            ->where('requester_actor_id', $record->requester_actor_id)->where('idempotency_key_hash', $record->idempotency_key_hash)
            ->where('status', $record->status)->where('execution_lease_token', $record->execution_lease_token)
            ->update(['status' => 'cancelled', 'cancel_requested_at' => $timestamp, 'cancelled_at' => $timestamp,
                'execution_lease_token' => null, 'execution_lease_expires_at' => null, 'execution_heartbeat_at' => null, 'updated_at' => $timestamp]);
        if ($updated !== 1) {
            throw new LogicException('report_owned_cleanup_cas_failed');
        }
    }
}
