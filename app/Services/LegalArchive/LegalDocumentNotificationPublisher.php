<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentNotificationDelivery;
use App\BusinessModules\Features\Notifications\Models\Notification as DatabaseNotification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LegalDocumentNotificationPublisher
{
    public function publish(
        LegalArchiveDocument $document,
        User $recipient,
        string $key,
        Notification $notification,
    ): void {
        $delivery = $this->claim(
            $document,
            $recipient,
            $key,
            $notification,
            $notification->toArray($recipient),
        );

        if ($delivery === null) {
            return;
        }

        $this->persistClaimed($delivery, $recipient);
    }

    public function persistClaimed(LegalDocumentNotificationDelivery $delivery, User $recipient): bool
    {
        return $this->persist($delivery, $recipient);
    }

    private function claim(
        LegalArchiveDocument $document,
        User $recipient,
        string $key,
        Notification $notification,
        array $payload,
    ): ?LegalDocumentNotificationDelivery {
        $token = Str::random(64);

        try {
            return DB::transaction(function () use ($document, $recipient, $key, $notification, $payload, $token): ?LegalDocumentNotificationDelivery {
                $delivery = LegalDocumentNotificationDelivery::query()
                    ->where([
                        'document_id' => $document->id,
                        'recipient_user_id' => $recipient->id,
                        'delivery_key' => $key,
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($delivery?->status === 'delivered'
                    || $delivery?->lease_expires_at?->isFuture()) {
                    return null;
                }

                $data = [
                    'status' => 'sending',
                    'notification_id' => $delivery?->notification_id ?? (string) Str::uuid(),
                    'notification_type' => $notification::class,
                    'notification_payload' => $payload,
                    'lease_token' => hash('sha256', $token),
                    'lease_expires_at' => now()->addMinutes(5),
                    'attempt_count' => ((int) ($delivery?->attempt_count ?? 0)) + 1,
                ];

                if ($delivery === null) {
                    return LegalDocumentNotificationDelivery::query()->create([
                        'document_id' => $document->id,
                        'recipient_user_id' => $recipient->id,
                        'delivery_key' => $key,
                        ...$data,
                    ]);
                }

                $delivery->forceFill($data)->save();

                return $delivery;
            });
        } catch (QueryException) {
            return null;
        }
    }

    private function persist(LegalDocumentNotificationDelivery $delivery, User $recipient): bool
    {
        return DB::transaction(function () use ($delivery, $recipient): bool {
            $locked = LegalDocumentNotificationDelivery::query()
                ->whereKey($delivery->id)
                ->where('lease_token', $delivery->lease_token)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof LegalDocumentNotificationDelivery || $locked->status === 'delivered') {
                return false;
            }

            DatabaseNotification::query()->firstOrCreate(
                ['id' => $locked->notification_id],
                [
                    'type' => $locked->notification_type,
                    'notifiable_type' => User::class,
                    'notifiable_id' => $recipient->id,
                    'organization_id' => $locked->document->organization_id,
                    'notification_type' => 'legal_archive',
                    'priority' => 'normal',
                    'channels' => ['in_app'],
                    'delivery_status' => ['in_app' => 'sent'],
                    'data' => $locked->notification_payload,
                    'metadata' => ['legal_document_delivery_id' => $locked->id],
                ],
            );

            $locked->forceFill([
                'status' => 'delivered',
                'delivered_at' => now(),
                'lease_expires_at' => null,
                'lease_token' => null,
            ])->save();

            return true;
        });
    }
}
