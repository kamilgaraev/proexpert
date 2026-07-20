<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentNotificationDelivery;
use Illuminate\Support\Facades\DB;
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
                DB::table('notifications')->insert([
                    'id' => (string) Str::uuid(),
                    'type' => (string) $delivery->notification_type,
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id' => (int) $delivery->recipient_user_id,
                    'data' => json_encode($delivery->notification_payload, JSON_THROW_ON_ERROR),
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                LegalDocumentNotificationDelivery::query()->whereKey($delivery->id)->where('lease_token', hash('sha256', $token))->update(['status' => 'delivered', 'delivered_at' => now(), 'lease_token' => null, 'lease_expires_at' => null]);
                $recovered++;
            } catch (\Throwable) {
                LegalDocumentNotificationDelivery::query()->whereKey($delivery->id)->where('lease_token', hash('sha256', $token))->update(['status' => 'failed', 'lease_token' => null, 'lease_expires_at' => null]);
            }
        }

        return $recovered;
    }
}
