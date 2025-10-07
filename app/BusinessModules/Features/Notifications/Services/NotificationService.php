<?php

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Channels\EmailChannel;
use App\BusinessModules\Features\Notifications\Channels\TelegramChannel;
use App\BusinessModules\Features\Notifications\Channels\InAppChannel;
use App\BusinessModules\Features\Notifications\Channels\WebSocketChannel;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected PreferenceManager $preferenceManager;

    public function __construct(PreferenceManager $preferenceManager)
    {
        $this->preferenceManager = $preferenceManager;
    }

    public function send(
        User $user,
        string $type,
        array $data,
        ?string $notificationType = 'system',
        ?string $priority = 'normal',
        ?array $channels = null,
        ?int $organizationId = null
    ): Notification {
        if (!$this->preferenceManager->canSend($user, $notificationType, $organizationId)) {
            Log::info('Notification skipped due to preferences', [
                'user_id' => $user->id,
                'notification_type' => $notificationType,
            ]);
            
            return $this->createNotification(
                $user,
                $type,
                $data,
                $notificationType,
                $priority,
                [],
                $organizationId
            );
        }

        $effectiveChannels = $channels ?? $this->preferenceManager->getChannels(
            $user,
            $notificationType,
            $organizationId
        );

        $notification = $this->createNotification(
            $user,
            $type,
            $data,
            $notificationType,
            $priority,
            $effectiveChannels,
            $organizationId
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
                $options['organization_id'] ?? null
            );

            $notifications->push($notification);
        }

        return $notifications;
    }

    protected function createNotification(
        User $user,
        string $type,
        array $data,
        string $notificationType,
        string $priority,
        array $channels,
        ?int $organizationId
    ): Notification {
        return Notification::create([
            'type' => $type,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'organization_id' => $organizationId,
            'notification_type' => $notificationType,
            'priority' => $priority,
            'channels' => $channels,
            'data' => $data,
            'delivery_status' => [],
        ]);
    }

    public function dispatch(Notification $notification): void
    {
        $priorityConfig = config("notifications.priorities.{$notification->priority}");
        $queue = $priorityConfig['queue'] ?? 'notifications';

        SendNotificationJob::dispatch($notification)
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

        if (!$channelClass) {
            Log::error('Unknown notification channel', ['channel' => $channel]);
            return false;
        }

        $notifiable = $notification->notifiable;

        if (!$notifiable) {
            Log::error('Notifiable not found', ['notification_id' => $notification->id]);
            return false;
        }

        try {
            $result = $channelClass->send($notifiable, $notification);

            $deliveryStatus = $notification->delivery_status ?? [];
            $deliveryStatus[$channel] = $result ? 'sent' : 'failed';
            $notification->update(['delivery_status' => $deliveryStatus]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Channel send failed', [
                'notification_id' => $notification->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            $deliveryStatus = $notification->delivery_status ?? [];
            $deliveryStatus[$channel] = 'failed';
            $notification->update(['delivery_status' => $deliveryStatus]);

            return false;
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

