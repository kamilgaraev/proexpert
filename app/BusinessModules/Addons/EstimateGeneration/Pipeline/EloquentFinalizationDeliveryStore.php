<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use Illuminate\Database\Connection;
use RuntimeException;

final readonly class EloquentFinalizationDeliveryStore implements FinalizationDeliveryStore
{
    public function __construct(private Connection $database) {}

    public function deliverOnce(FinalizationDeliveryReceipt $receipt, callable $deliver): void
    {
        $this->database->transaction(function () use ($receipt, $deliver): void {
            $now = now();
            $this->database->table('estimate_generation_finalization_deliveries')->insertOrIgnore([
                'organization_id' => $receipt->organizationId, 'project_id' => $receipt->projectId,
                'session_id' => $receipt->sessionId, 'generation_attempt_id' => $receipt->generationAttemptId,
                'event_type' => $receipt->eventType, 'recipient_id' => $receipt->recipientId,
                'business_key' => $receipt->businessKey, 'status' => 'pending',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $stored = $this->database->table('estimate_generation_finalization_deliveries')
                ->where('business_key', $receipt->businessKey)->lockForUpdate()->first();
            if ($stored === null || (int) $stored->organization_id !== $receipt->organizationId
                || (int) $stored->project_id !== $receipt->projectId || (int) $stored->session_id !== $receipt->sessionId
                || (int) $stored->recipient_id !== $receipt->recipientId
                || ! hash_equals((string) $stored->generation_attempt_id, $receipt->generationAttemptId)
                || ! hash_equals((string) $stored->event_type, $receipt->eventType)) {
                throw new RuntimeException('estimate_generation.finalization_delivery_identity_collision');
            }
            if ((string) $stored->status === 'delivered') {
                return;
            }
            $notification = $deliver();
            if ($this->database->table('estimate_generation_finalization_deliveries')
                ->where('id', $stored->id)->where('status', 'pending')->update([
                    'status' => 'delivered',
                    'notification_id' => ($notification->exists ?? false) ? $notification->getKey() : null,
                    'delivered_at' => now(), 'updated_at' => now(),
                ]) !== 1) {
                throw new RuntimeException('estimate_generation.finalization_delivery_claim_lost');
            }
        }, 3);
    }
}
