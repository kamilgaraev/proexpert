<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionDeliveryStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionTrigger;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSubscriptionDeliveryRecord;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class EloquentReportSubscriptionDeliveryStore implements ReportSubscriptionDeliveryStore
{
    public function __construct(private readonly ReportSubscriptionStore $subscriptions) {}

    public function lockWithSubscription(string $id): array
    {
        $delivery = ReportSubscriptionDeliveryRecord::query()
            ->where('id', $id)
            ->lockForUpdate()
            ->firstOrFail();

        return [
            $this->dto($delivery),
            $this->subscriptions->lock((string) $delivery->subscription_id),
        ];
    }

    public function createCalendarScheduledLocked(
        ReportSubscription $subscription,
        DateTimeImmutable $scheduledFor,
        string $bytes,
        Sha256Hash $hash,
        int $version,
    ): ?ReportSubscriptionDelivery {
        $attributes = $this->attributes($subscription, $scheduledFor, 'calendar', $bytes, $hash, $version);

        if (ReportSubscriptionDeliveryRecord::query()->insertOrIgnore($attributes) !== 1) {
            return null;
        }

        return $this->dto(
            ReportSubscriptionDeliveryRecord::query()
                ->where('id', $attributes['id'])
                ->lockForUpdate()
                ->firstOrFail(),
        );
    }

    public function insertManualScheduledOnConflictLocked(
        ReportSubscription $subscription,
        DateTimeImmutable $scheduledFor,
        Sha256Hash $triggerKeyHash,
        Sha256Hash $manualRequestHash,
        string $bytes,
        Sha256Hash $hash,
        int $version,
    ): ?string {
        $attributes = $this->attributes($subscription, $scheduledFor, 'manual', $bytes, $hash, $version) + [
            'trigger_key_hash' => $triggerKeyHash->value,
            'manual_request_sha256' => $manualRequestHash->value,
        ];

        return ReportSubscriptionDeliveryRecord::query()->insertOrIgnore($attributes) === 1
            ? $attributes['id']
            : null;
    }

    public function lockManualByScope(string $subscriptionId, Sha256Hash $triggerKeyHash): ?ReportSubscriptionDelivery
    {
        $record = ReportSubscriptionDeliveryRecord::query()
            ->where('subscription_id', $subscriptionId)
            ->where('trigger_key_hash', $triggerKeyHash->value)
            ->lockForUpdate()
            ->first();

        return $record === null ? null : $this->dto($record);
    }

    public function beginAttemptLocked(ReportSubscriptionDelivery $delivery): void
    {
        $this->move($delivery, 'scheduled', [
            'status' => 'building_run',
            'attempt' => $delivery->attempt + 1,
            'retry_at' => null,
        ]);
    }

    public function attachRunLocked(ReportSubscriptionDelivery $delivery, string $runId): void
    {
        $this->move($delivery, 'building_run', ['run_id' => $runId]);
    }

    public function attachExportLocked(ReportSubscriptionDelivery $delivery, string $exportId): void
    {
        $this->move($delivery, 'building_run', [
            'status' => 'building_export',
            'export_id' => $exportId,
        ]);
    }

    public function markReadyLocked(ReportSubscriptionDelivery $delivery): void
    {
        $this->move($delivery, 'building_export', ['status' => 'ready']);
    }

    public function markNotifiedLocked(ReportSubscriptionDelivery $delivery, string $receiptId, Sha256Hash $key): void
    {
        $this->move($delivery, 'ready', [
            'status' => 'notified',
            'notification_receipt_id' => $receiptId,
            'notification_key_hash' => $key->value,
            'notified_at' => now(),
        ]);
    }

    public function rescheduleRetryLocked(ReportSubscriptionDelivery $delivery, DateTimeImmutable $retryAt, string $code): void
    {
        $this->move($delivery, $delivery->status->value, [
            'status' => 'scheduled',
            'retry_at' => $retryAt,
            'safe_error_code' => $code,
        ]);
    }

    public function markFailedLocked(ReportSubscriptionDelivery $delivery, string $code): void
    {
        $this->move($delivery, $delivery->status->value, [
            'status' => 'failed',
            'safe_error_code' => $code,
        ]);
    }

    public function markExpiredLocked(ReportSubscriptionDelivery $delivery): void
    {
        $this->move($delivery, $delivery->status->value, ['status' => 'expired']);
    }

    public function expireExecutionsDueLocked(DateTimeImmutable $now, int $limit): array
    {
        return DB::transaction(function () use ($now, $limit): array {
            $records = ReportSubscriptionDeliveryRecord::query()
                ->whereIn('status', ['scheduled', 'building_run', 'building_export', 'ready'])
                ->where('execution_expires_at', '<=', $now)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->limit($limit)
                ->get();

            $ids = [];
            foreach ($records as $record) {
                $record->status = 'expired';
                $record->save();
                $ids[] = (string) $record->id;
            }

            return $ids;
        });
    }

    public function pruneTerminalDueLocked(DateTimeImmutable $now, int $limit): int
    {
        return DB::transaction(function () use ($now, $limit): int {
            $ids = ReportSubscriptionDeliveryRecord::query()
                ->whereIn('status', ['notified', 'failed', 'expired'])
                ->where('retention_delete_after', '<=', $now)
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            DB::table('report_subscription_notification_receipts')
                ->whereIn('delivery_id', $ids->all())
                ->delete();

            return ReportSubscriptionDeliveryRecord::destroy($ids->all());
        });
    }

    private function move(ReportSubscriptionDelivery $delivery, string $expected, array $changes): void
    {
        if (
            ReportSubscriptionDeliveryRecord::query()
                ->where('id', $delivery->id)
                ->where('status', $expected)
                ->update($changes) !== 1
        ) {
            throw new LogicException('report_subscription_delivery_concurrent_change');
        }
    }

    private function attributes(
        ReportSubscription $subscription,
        DateTimeImmutable $scheduledFor,
        string $trigger,
        string $bytes,
        Sha256Hash $hash,
        int $version,
    ): array {
        $now = now();

        return [
            'id' => (string) Str::ulid(),
            'organization_id' => $subscription->organizationId,
            'owner_id' => $subscription->ownerId,
            'subscription_id' => $subscription->id,
            'trigger' => $trigger,
            'scheduled_for' => $scheduledFor,
            'execution_input_bytes' => $bytes,
            'execution_input_sha256' => $hash->value,
            'subscription_version' => $version,
            'status' => 'scheduled',
            'attempt' => 0,
            'execution_expires_at' => $scheduledFor->modify('+'.(int) config('reporting_subscriptions.execution_ttl_seconds').' seconds'),
            'retention_delete_after' => $now->copy()->addDays((int) config('reporting_subscriptions.retention_days')),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function dto(ReportSubscriptionDeliveryRecord $record): ReportSubscriptionDelivery
    {
        $carbon = fn (mixed $value): DateTimeImmutable => DateTimeImmutable::createFromInterface($value);

        return new ReportSubscriptionDelivery(
            (string) $record->id,
            (int) $record->organization_id,
            (int) $record->owner_id,
            (string) $record->subscription_id,
            ReportSubscriptionTrigger::from((string) $record->trigger),
            $record->trigger_key_hash === null ? null : new Sha256Hash((string) $record->trigger_key_hash),
            $record->manual_request_sha256 === null ? null : new Sha256Hash((string) $record->manual_request_sha256),
            $carbon($record->scheduled_for),
            (string) $record->execution_input_bytes,
            new Sha256Hash((string) $record->execution_input_sha256),
            (int) $record->subscription_version,
            ReportSubscriptionDeliveryStatus::from((string) $record->status),
            (int) $record->attempt,
            $record->run_id === null ? null : (string) $record->run_id,
            $record->export_id === null ? null : (string) $record->export_id,
            $record->notification_receipt_id === null ? null : (string) $record->notification_receipt_id,
            $record->safe_error_code === null ? null : (string) $record->safe_error_code,
            $record->retry_at === null ? null : $carbon($record->retry_at),
            $carbon($record->execution_expires_at),
            $carbon($record->retention_delete_after),
        );
    }
}
