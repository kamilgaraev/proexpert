<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Channels;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WebSocketChannel
{
    public function __construct(private readonly Factory $broadcasting) {}

    public function send($notifiable, Notification $notification): bool
    {
        if (config('broadcasting.default') !== 'reverb') {
            throw new RuntimeException('WebSocket channel requires the Reverb broadcasting connection');
        }

        $analytics = NotificationAnalytics::create([
            'notification_id' => $notification->id,
            'channel' => 'websocket',
            'status' => 'pending',
        ]);

        try {
            $this->broadcastNotification($notification, $notifiable);
            $analytics->updateStatus('sent');

            Log::info('WebSocket notification broadcasted', [
                'notification_id' => $notification->id,
                'notifiable_id' => $notifiable->id,
            ]);

            return true;
        } catch (Throwable $exception) {
            $analytics->updateStatus('failed');
            $analytics->update(['error_message' => $exception->getMessage()]);

            Log::warning('WebSocket notification failed', [
                'notification_id' => $notification->id,
                'notifiable_id' => $notifiable->id,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    protected function broadcastNotification(Notification $notification, object $notifiable): void
    {
        $interface = $notification->data['interface'] ?? null;

        if (! in_array($interface, ['admin', 'lk'], true)) {
            throw new RuntimeException("Unsupported notification interface: {$interface}");
        }

        $channel = 'private-App.Models.User.'.$notifiable->id.'.'.$interface;

        $this->broadcasting->connection('reverb')->broadcast(
            [$channel],
            'notification.new',
            [
                'id' => $notification->id,
                'type' => $notification->type,
                'notification_type' => $notification->notification_type,
                'priority' => $notification->priority,
                'data' => $notification->data,
                'created_at' => $notification->created_at->toIso8601String(),
                'read_at' => $notification->read_at?->toIso8601String(),
            ]
        );
    }
}
