<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Notifications;

use App\BusinessModules\Core\Reporting\Domain\Contracts\InAppReportSubscriptionNotifier;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionNotificationReceipt;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
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
    ): ReportSubscriptionNotificationReceipt {
        return DB::transaction(function () use ($context, $subscription, $delivery, $export, $key): ReportSubscriptionNotificationReceipt {
            $receipt = DB::table('report_subscription_notification_receipts')
                ->where('idempotency_key_hash', $key->hash)
                ->lockForUpdate()
                ->first();

            if ($receipt !== null) {
                return new ReportSubscriptionNotificationReceipt(
                    (string) $receipt->id,
                    new DateTimeImmutable((string) $receipt->created_at),
                );
            }

            $id = 'reports-subscription-'.$delivery->id;
            $user = User::query()->findOrFail($subscription->ownerId);
            $notification = $this->notifications->send(
                $user,
                'report_subscription_ready',
                [
                    'report_code' => $subscription->reportCode,
                    'export_id' => $export->id,
                    'correlation_id' => $context->correlationId(),
                ],
                channels: ['in_app'],
                organizationId: $subscription->organizationId,
                requiredPermissions: ['reports.view'],
                interfaces: ['admin'],
            );

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

            return new ReportSubscriptionNotificationReceipt($id, DateTimeImmutable::createFromInterface($now));
        });
    }
}
