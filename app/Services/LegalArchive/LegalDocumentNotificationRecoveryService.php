<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentNotificationDelivery;
use App\Notifications\LegalArchive\LegalDocumentApprovalRequiredNotification;
use App\Notifications\LegalArchive\LegalDocumentDeadlineNotification;
use App\Notifications\LegalArchive\LegalDocumentSignatureRequiredNotification;
use Illuminate\Support\Str;

final class LegalDocumentNotificationRecoveryService
{
    public function recoverExpired(int $limit = 100): int
    {
        $deliveries = LegalDocumentNotificationDelivery::query()
            ->where('status', 'sending')
            ->where('lease_expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->get();

        $recovered = 0;
        foreach ($deliveries as $delivery) {
            $token = Str::random(64);
            $claimed = LegalDocumentNotificationDelivery::query()
                ->whereKey($delivery->id)
                ->where('status', 'sending')
                ->where('lease_expires_at', '<=', now())
                ->update(['lease_token' => hash('sha256', $token), 'lease_expires_at' => now()->addMinutes(5), 'attempt_count' => ((int) $delivery->attempt_count) + 1]);
            if ($claimed !== 1) {
                continue;
            }
            try {
                $delivery->loadMissing(['document', 'recipient']);
                $notification = match ($delivery->notification_type) {
                    LegalDocumentApprovalRequiredNotification::class => new LegalDocumentApprovalRequiredNotification($delivery->document),
                    LegalDocumentSignatureRequiredNotification::class => new LegalDocumentSignatureRequiredNotification($delivery->document),
                    LegalDocumentDeadlineNotification::class => new LegalDocumentDeadlineNotification(
                        $delivery->document,
                        str_starts_with((string) data_get($delivery->notification_payload, 'type'), 'legal_document_')
                            ? substr((string) data_get($delivery->notification_payload, 'type'), strlen('legal_document_'))
                            : 'obligation_due',
                    ),
                    default => throw new \LogicException('Unsupported legal notification delivery type.'),
                };
                $delivery->recipient->notify($notification);
                LegalDocumentNotificationDelivery::query()->whereKey($delivery->id)->where('lease_token', hash('sha256', $token))->update(['status' => 'delivered', 'delivered_at' => now(), 'lease_token' => null, 'lease_expires_at' => null]);
                $recovered++;
            } catch (\Throwable) {
                LegalDocumentNotificationDelivery::query()->whereKey($delivery->id)->where('lease_token', hash('sha256', $token))->update(['status' => 'sending', 'lease_token' => null, 'lease_expires_at' => now()->subSecond()]);
            }
        }

        return $recovered;
    }
}
