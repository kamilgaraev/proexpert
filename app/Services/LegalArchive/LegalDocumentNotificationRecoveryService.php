<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentNotificationDelivery;
use Illuminate\Support\Str;

final class LegalDocumentNotificationRecoveryService
{
    public function __construct(private readonly LegalDocumentNotificationPublisher $publisher) {}

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
            $leaseToken = hash('sha256', $token);
            $notificationId = $delivery->notification_id ?? (string) Str::uuid();
            $claimed = LegalDocumentNotificationDelivery::query()
                ->whereKey($delivery->id)
                ->where('status', 'sending')
                ->where('lease_expires_at', '<=', now())
                ->update([
                    'notification_id' => $notificationId,
                    'lease_token' => $leaseToken,
                    'lease_expires_at' => now()->addMinutes(5),
                    'attempt_count' => ((int) $delivery->attempt_count) + 1,
                ]);

            if ($claimed !== 1) {
                continue;
            }

            $delivery->forceFill([
                'notification_id' => $notificationId,
                'lease_token' => $leaseToken,
            ]);

            try {
                $delivery->loadMissing('recipient');
                if ($delivery->recipient === null) {
                    LegalDocumentNotificationDelivery::query()
                        ->whereKey($delivery->id)
                        ->where('lease_token', $leaseToken)
                        ->update([
                            'status' => 'discarded',
                            'lease_token' => null,
                            'lease_expires_at' => null,
                        ]);

                    continue;
                }

                if ($this->publisher->persistClaimed($delivery, $delivery->recipient)) {
                    $recovered++;
                }
            } catch (\Throwable) {
                LegalDocumentNotificationDelivery::query()
                    ->whereKey($delivery->id)
                    ->where('lease_token', $leaseToken)
                    ->update([
                        'status' => 'sending',
                        'lease_token' => null,
                        'lease_expires_at' => now()->subSecond(),
                    ]);
            }
        }

        return $recovered;
    }
}
