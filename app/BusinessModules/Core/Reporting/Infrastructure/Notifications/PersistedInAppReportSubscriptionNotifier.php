<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Notifications;

use App\BusinessModules\Core\Reporting\Domain\Contracts\InAppReportSubscriptionNotifier;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionNotificationReceipt;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class PersistedInAppReportSubscriptionNotifier implements InAppReportSubscriptionNotifier
{
    public function __construct(private NotificationService $notifications) {}

    public function notify(
        ReportExecutionContext $context,
        ReportSubscription $subscription,
        ReportSubscriptionDelivery $delivery,
        ReportExport $export,
        IdempotencyKey $key,
    ): ?ReportSubscriptionNotificationReceipt {
        try {
            return $this->persistOrInspect($context, $subscription, $delivery, $export, $key);
        } catch (ReportSubscriptionNotificationUnavailable) {
            return null;
        }
    }

    private function persistOrInspect(
        ReportExecutionContext $context,
        ReportSubscription $subscription,
        ReportSubscriptionDelivery $delivery,
        ReportExport $export,
        IdempotencyKey $key,
    ): ?ReportSubscriptionNotificationReceipt {
        return DB::transaction(function () use ($context, $subscription, $delivery, $export, $key): ?ReportSubscriptionNotificationReceipt {
            if ($context->actor->id !== $delivery->ownerId || $subscription->ownerId !== $delivery->ownerId
                || $context->scope->organizationId !== $delivery->organizationId || $subscription->organizationId !== $delivery->organizationId
                || $subscription->id !== $delivery->subscriptionId || $delivery->runId !== $export->runId
                || $delivery->exportId !== $export->id || $export->status !== ReportExportStatus::READY) {
                throw new ReportSubscriptionNotificationUnavailable('report_subscription_notification_unavailable');
            }
            $receipt = DB::table('report_subscription_notification_receipts')
                ->where('idempotency_key_hash', $key->hash)
                ->where('delivery_id', $delivery->id)
                ->where('owner_id', $subscription->ownerId)
                ->where('organization_id', $subscription->organizationId)
                ->lockForUpdate()
                ->first();

            if ($receipt !== null) {
                return $this->deliveredReceipt(
                    (string) $receipt->id, new DateTimeImmutable((string) $receipt->created_at),
                    (string) $receipt->notification_id, $subscription,
                );
            }

            $id = 'reports-subscription-'.$delivery->id;
            $user = User::query()->findOrFail($subscription->ownerId);
            $notification = $this->notifications->send(
                $user,
                'report_subscription_ready',
                [
                    'title' => __('reports.subscriptions.ready_title'),
                    'message' => __('reports.subscriptions.ready_message'),
                    'target_route' => '/reports/exports/'.$export->id,
                    'actions' => [[
                        'label' => __('reports.subscriptions.open_file'),
                        'route' => '/reports/exports/'.$export->id,
                    ]],
                    'report_code' => $delivery->executionInput()->reportCode,
                    'export_id' => $export->id,
                    'format' => $export->format,
                    'correlation_id' => $context->correlationId(),
                ],
                channels: ['in_app'],
                organizationId: $subscription->organizationId,
                requiredPermissions: ['reports.view'],
                interfaces: ['admin'],
            );
            if (! $notification->exists) {
                return null;
            }
            if (! in_array('in_app', $notification->channels ?? [], true)) {
                throw new ReportSubscriptionNotificationUnavailable('report_subscription_notification_unavailable');
            }

            $now = now();
            DB::table('report_subscription_notification_receipts')->insert([
                'id' => $id,
                'idempotency_key_hash' => $key->hash,
                'delivery_id' => $delivery->id,
                'owner_id' => $subscription->ownerId,
                'organization_id' => $subscription->organizationId,
                'notification_id' => $notification->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->deliveredReceipt($id, DateTimeImmutable::createFromInterface($now), (string) $notification->id, $subscription);
        });
    }

    private function deliveredReceipt(
        string $id,
        DateTimeImmutable $createdAt,
        string $notificationId,
        ReportSubscription $subscription,
    ): ?ReportSubscriptionNotificationReceipt {
        $notification = Notification::query()
            ->whereKey($notificationId)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $subscription->ownerId)
            ->where('organization_id', $subscription->organizationId)
            ->first();
        if (! $notification instanceof Notification || ! in_array('in_app', $notification->channels ?? [], true)) {
            throw new ReportSubscriptionNotificationUnavailable('report_subscription_notification_unavailable');
        }
        if (! in_array($notification->delivery_status['in_app'] ?? null, ['sent', 'delivered'], true)
            || ! $notification->analytics()->where('channel', 'in_app')->where('status', 'delivered')->exists()) {
            return null;
        }

        return new ReportSubscriptionNotificationReceipt($id, $createdAt);
    }
}
