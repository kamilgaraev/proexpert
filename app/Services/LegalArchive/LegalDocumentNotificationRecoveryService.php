<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentNotificationDelivery;

final class LegalDocumentNotificationRecoveryService
{
    public function recoverExpired(int $limit = 100): int
    {
        return LegalDocumentNotificationDelivery::query()
            ->where('status', 'sending')
            ->where('lease_expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->update([
                'status' => 'failed',
                'lease_expires_at' => null,
                'lease_token' => null,
                'updated_at' => now(),
            ]);
    }
}
