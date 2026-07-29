<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExpiredExecutionLease;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class EloquentReportExportLeaseRecoveryStore implements ReportExportLeaseRecoveryStore
{
    public function __construct(private ReportDispatchIntentStore $dispatchIntents) {}

    public function due(int $limit, DateTimeImmutable $occurredAt): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('report_execution_watchdog_limit_invalid');
        }

        return ReportExportRecord::query()
            ->whereIn('status', [
                ReportExportStatus::RUNNING->value,
                ReportExportStatus::UPLOADING->value,
            ])
            ->whereNotNull('execution_lease_token')
            ->whereNotNull('execution_lease_expires_at')
            ->where('execution_lease_expires_at', '<=', $this->timestamp($occurredAt))
            ->orderBy('execution_lease_expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->get([
                'id',
                'organization_id',
                'execution_lease_token',
                'execution_lease_expires_at',
            ])
            ->map(function (ReportExportRecord $record): ReportExpiredExecutionLease {
                $expiry = $this->instant($record->execution_lease_expires_at);
                if (! $expiry instanceof DateTimeImmutable) {
                    throw new \RuntimeException('report_execution_lease_expiry_invalid');
                }

                return new ReportExpiredExecutionLease(
                    'export',
                    (string) $record->id,
                    (int) $record->organization_id,
                    (string) $record->execution_lease_token,
                    $expiry,
                );
            })
            ->all();
    }

    public function requeue(
        ReportExpiredExecutionLease $lease,
        DateTimeImmutable $occurredAt,
    ): bool {
        if ($lease->aggregateKind !== 'export') {
            return false;
        }

        return DB::transaction(function () use ($lease, $occurredAt): bool {
            $record = ReportExportRecord::query()
                ->whereKey($lease->aggregateId)
                ->where('organization_id', $lease->organizationId)
                ->lockForUpdate()
                ->first();
            if (
                ! $record instanceof ReportExportRecord
                || ! in_array($record->status, [
                    ReportExportStatus::RUNNING->value,
                    ReportExportStatus::UPLOADING->value,
                ], true)
                || ! is_string($record->execution_lease_token)
                || ! hash_equals(
                    $record->execution_lease_token,
                    $lease->expectedLeaseToken,
                )
                || $this->instant($record->execution_lease_expires_at)
                    ?->format('Y-m-d\TH:i:s.uP')
                    !== $lease->leaseExpiresAt->format('Y-m-d\TH:i:s.uP')
                || $lease->leaseExpiresAt > $occurredAt
            ) {
                return false;
            }

            $updated = ReportExportRecord::query()
                ->whereKey($record->id)
                ->where('organization_id', $lease->organizationId)
                ->whereIn('status', [
                    ReportExportStatus::RUNNING->value,
                    ReportExportStatus::UPLOADING->value,
                ])
                ->where('execution_lease_token', $lease->expectedLeaseToken)
                ->where(
                    'execution_lease_expires_at',
                    '<=',
                    $this->timestamp($occurredAt),
                )
                ->update([
                    'status' => ReportExportStatus::QUEUED->value,
                    'execution_lease_token' => null,
                    'execution_lease_expires_at' => null,
                    'execution_heartbeat_at' => null,
                    'updated_at' => $this->timestamp($occurredAt),
                ]);
            if ($updated !== 1) {
                return false;
            }

            $eventKey = "reports:export:{$lease->aggregateId}:generate:recovery:"
                .$lease->expectedLeaseToken;
            $this->dispatchIntents->addExportIntent(
                $lease->aggregateId,
                $lease->organizationId,
                $eventKey,
                $occurredAt,
            );

            return true;
        });
    }

    private function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : null;
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }
}
