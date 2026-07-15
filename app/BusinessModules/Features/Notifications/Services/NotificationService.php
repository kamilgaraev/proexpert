<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Channels\EmailChannel;
use App\BusinessModules\Features\Notifications\Channels\InAppChannel;
use App\BusinessModules\Features\Notifications\Channels\TelegramChannel;
use App\BusinessModules\Features\Notifications\Channels\WebSocketChannel;
use App\BusinessModules\Features\Notifications\Contracts\NotificationPersistence;
use App\BusinessModules\Features\Notifications\DTOs\NotificationDeliveryOptions;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class NotificationService
{
    protected PreferenceManager $preferenceManager;

    public function __construct(
        PreferenceManager $preferenceManager,
        private readonly NotificationPayloadNormalizer $payloadNormalizer,
        private readonly NotificationRecipientPermissionResolver $permissionResolver,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly NotificationPersistence $persistence,
    ) {
        $this->preferenceManager = $preferenceManager;
    }

    public function send(
        User $user,
        string $type,
        array $data,
        ?string $notificationType = 'system',
        ?string $priority = 'normal',
        ?array $channels = null,
        ?int $organizationId = null,
        string|array|null $requiredPermissions = null,
        string|array|null $interfaces = null,
    ): Notification {
        $notificationType = $notificationType ?? 'system';
        $priority = $priority ?? 'normal';
        $resolvedInterfaces = $this->targetResolver->resolve(
            is_array($interfaces) ? $interfaces : ($interfaces === null ? [] : [$interfaces]),
            $data,
        );
        $data = $this->payloadNormalizer->normalize($type, $data, $notificationType);
        $data = $this->canonicalizeInterface($data, $resolvedInterfaces);

        $forceSend = $data['force_send'] ?? false;
        $requiredPermissions = $this->permissionResolver->requiredPermissions(
            $type,
            $notificationType,
            $data,
            $requiredPermissions
        );

        if (! $this->permissionResolver->canReceive($user, $requiredPermissions, $organizationId, $data)) {
            Log::info('Notification skipped due to recipient permissions', [
                'user_id' => $user->id,
                'notification_type' => $notificationType,
                'required_permissions' => $requiredPermissions,
            ]);

            return $this->makeSkippedNotification(
                $user,
                $type,
                $data,
                $notificationType,
                $priority,
                new NotificationDeliveryOptions(
                    [],
                    $resolvedInterfaces,
                    $organizationId,
                    $requiredPermissions,
                ),
            );
        }

        if (! $forceSend && ! $this->preferenceManager->canSend($user, $notificationType, $organizationId)) {
            Log::info('Notification skipped due to preferences', [
                'user_id' => $user->id,
                'notification_type' => $notificationType,
            ]);

            return $this->persistence->persist(
                $user,
                $type,
                $data,
                $notificationType,
                $priority,
                new NotificationDeliveryOptions(
                    [],
                    $resolvedInterfaces,
                    $organizationId,
                    $requiredPermissions,
                ),
            );
        }

        if ($forceSend) {
            $effectiveChannels = $channels ?? ['in_app', 'websocket', 'email'];
            Log::info('Force sending critical notification', [
                'user_id' => $user->id,
                'notification_type' => $notificationType,
                'channels' => $effectiveChannels,
                'priority' => $priority,
            ]);
        } else {
            $effectiveChannels = $channels ?? $this->preferenceManager->getChannels(
                $user,
                $notificationType,
                $organizationId
            );
        }

        $this->assertWebSocketTargetsSupported($effectiveChannels, $resolvedInterfaces);

        $notification = $this->persistence->persist(
            $user,
            $type,
            $data,
            $notificationType,
            $priority,
            new NotificationDeliveryOptions(
                $effectiveChannels,
                $resolvedInterfaces,
                $organizationId,
                $requiredPermissions,
            ),
        );

        $this->dispatch($notification);

        return $notification;
    }

    public function sendBulk(Collection $users, string $type, array $data, array $options = []): Collection
    {
        $notifications = collect();

        foreach ($users as $user) {
            $notification = $this->send(
                $user,
                $type,
                $data,
                $options['notification_type'] ?? 'system',
                $options['priority'] ?? 'normal',
                $options['channels'] ?? null,
                $options['organization_id'] ?? null,
                $options['required_permissions'] ?? null,
                $options['interfaces'] ?? null,
            );

            if ($notification->exists) {
                $notifications->push($notification);
            }
        }

        return $notifications;
    }

    private function canonicalizeInterface(array $data, array $interfaces): array
    {
        if (count($interfaces) === 1 && $interfaces[0] instanceof NotificationInterface) {
            $data['interface'] = $interfaces[0]->value;

            return $data;
        }

        unset($data['interface']);

        return $data;
    }

    private function assertWebSocketTargetsSupported(array $channels, array $interfaces): void
    {
        if (! in_array('websocket', $channels, true)) {
            return;
        }

        $unsupportedInterface = collect($interfaces)->contains(
            static fn ($interface): bool => ! $interface instanceof NotificationInterface || ! in_array(
                $interface,
                [NotificationInterface::Admin, NotificationInterface::Lk],
                true,
            )
        );

        if ($interfaces === [] || $unsupportedInterface) {
            throw new DomainException('WebSocket delivery requires supported notification interfaces');
        }
    }

    private function makeSkippedNotification(
        User $user,
        string $type,
        array $data,
        string $notificationType,
        string $priority,
        NotificationDeliveryOptions $options,
    ): Notification {
        return new Notification([
            'type' => $type,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'organization_id' => $options->organizationId,
            'notification_type' => $notificationType,
            'priority' => $priority,
            'channels' => [],
            'data' => $data,
            'delivery_status' => [],
            'metadata' => [
                'required_permissions' => $options->requiredPermissions,
            ],
        ]);
    }

    public function dispatch(Notification $notification): void
    {
        $priorityConfig = config("notifications.priorities.{$notification->priority}");
        $queue = $priorityConfig['queue'] ?? 'notifications';

        SendNotificationJob::dispatch($notification)
            ->afterCommit()
            ->onQueue($queue);

        Log::info('Notification dispatched to queue', [
            'notification_id' => $notification->id,
            'priority' => $notification->priority,
            'queue' => $queue,
            'channels' => $notification->channels,
        ]);
    }

    public function sendViaChannel(Notification $notification, string $channel): bool
    {
        $channelClass = $this->getChannelClass($channel);

        if (! $channelClass) {
            Log::error('Unknown notification channel', ['channel' => $channel]);
            throw new RuntimeException("Unknown notification channel: {$channel}");
        }

        $notifiable = $notification->notifiable;

        if (! $notifiable) {
            Log::error('Notifiable not found', ['notification_id' => $notification->id]);
            throw new RuntimeException("Notification recipient not found: {$notification->id}");
        }

        try {
            $result = $channelClass->send($notifiable, $notification);

            if (! $result) {
                throw new RuntimeException("Notification channel {$channel} reported a failed delivery");
            }

            $deliveryStatus = $notification->delivery_status ?? [];
            $deliveryStatus[$channel] = $result ? 'sent' : 'failed';
            $notification->update(['delivery_status' => $deliveryStatus]);

            return $result;
        } catch (Throwable $e) {
            Log::error('Channel send failed', [
                'notification_id' => $notification->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            $deliveryStatus = $notification->delivery_status ?? [];
            $deliveryStatus[$channel] = 'failed';
            $notification->update(['delivery_status' => $deliveryStatus]);

            throw $e;
        }
    }

    protected function getChannelClass(string $channel)
    {
        $channels = [
            'email' => app(EmailChannel::class),
            'telegram' => app(TelegramChannel::class),
            'in_app' => app(InAppChannel::class),
            'websocket' => app(WebSocketChannel::class),
        ];

        return $channels[$channel] ?? null;
    }
}
