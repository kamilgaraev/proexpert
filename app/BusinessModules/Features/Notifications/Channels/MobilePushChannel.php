<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Channels;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationDevice;
use App\BusinessModules\Features\Notifications\Models\NotificationDeviceDelivery;
use App\BusinessModules\Features\Notifications\Services\PushDeliveryException;
use App\BusinessModules\Features\Notifications\Services\RustorePushTransport;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class MobilePushChannel
{
    public function __construct(private readonly RustorePushTransport $rustore) {}

    public function send(User $user, Notification $notification): bool
    {
        $devices = NotificationDevice::query()
            ->where('user_id', $user->id)
            ->where('platform', 'android')
            ->where('provider', 'rustore')
            ->get();
        $hasFailure = false;

        foreach ($devices as $device) {
            $delivery = NotificationDeviceDelivery::query()->firstOrCreate(
                ['notification_id' => $notification->id, 'installation_id' => $device->installation_id],
                ['device_id' => $device->id, 'token_hash' => $device->token_hash, 'status' => 'pending', 'attempts' => 0],
            );
            if ($delivery->status === 'sent') {
                continue;
            }

            try {
                $messageId = $this->rustore->send(
                    $device->token,
                    (string) ($notification->data['title'] ?? ''),
                    (string) ($notification->data['message'] ?? ''),
                    $this->payload($notification),
                );
                $delivery->forceFill([
                    'device_id' => $device->id,
                    'token_hash' => $device->token_hash,
                    'status' => 'sent',
                    'message_id' => $messageId,
                    'attempts' => $delivery->attempts + 1,
                    'last_error' => null,
                    'sent_at' => now(),
                ])->save();
            } catch (Throwable $e) {
                $invalid = $e instanceof PushDeliveryException && $e->unregisteredToken;
                $safeError = $e instanceof PushDeliveryException ? $e->getMessage() : 'Push provider transport failed';
                $delivery->forceFill([
                    'device_id' => $device->id,
                    'token_hash' => $device->token_hash,
                    'status' => 'failed',
                    'attempts' => $delivery->attempts + 1,
                    'last_error' => mb_substr($safeError, 0, 2000),
                    'sent_at' => null,
                ])->save();

                if ($invalid) {
                    $device->delete();
                    $delivery->forceFill(['device_id' => null])->save();
                } else {
                    $hasFailure = true;
                }

                Log::warning('notifications.push.device_delivery_failed', [
                    'notification_id' => $notification->id,
                    'installation_id' => $device->installation_id,
                    'provider' => $device->provider,
                    'error' => $safeError,
                ]);
            }
        }

        if ($hasFailure) {
            throw new RuntimeException('One or more mobile push deliveries failed');
        }

        return true;
    }

    /** @return array<string, string> */
    private function payload(Notification $notification): array
    {
        $data = $notification->data ?? [];
        $entity = is_array($data['entity'] ?? null) ? $data['entity'] : [];
        $targetType = $data['target_type'] ?? $entity['type'] ?? null;
        $targetId = $data['target_id'] ?? $entity['id'] ?? null;
        $route = $data['route'] ?? $data['target_route'] ?? null;
        if (! is_string($route) && $targetType === 'site_request' && is_scalar($targetId)) {
            $route = '/site-requests/'.(string) $targetId;
        }

        return array_filter([
            'notification_id' => (string) $notification->id,
            'organization_id' => is_numeric($data['organization_id'] ?? null) ? (string) (int) $data['organization_id'] : null,
            'project_id' => is_numeric($data['project_id'] ?? null) ? (string) (int) $data['project_id'] : null,
            'journal_id' => is_numeric($data['journal_id'] ?? null) ? (string) (int) $data['journal_id'] : null,
            'warehouse_id' => is_numeric($data['warehouse_id'] ?? null) ? (string) (int) $data['warehouse_id'] : null,
            'target_type' => is_scalar($targetType) ? (string) $targetType : null,
            'target_id' => is_scalar($targetId) ? (string) $targetId : null,
            'route' => is_string($route) ? $route : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
