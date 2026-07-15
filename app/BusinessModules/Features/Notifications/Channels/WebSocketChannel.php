<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Channels;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Support\Collection;
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
        $targets = $this->targets($notification);

        if ($targets->isEmpty()) {
            throw new RuntimeException('WebSocket delivery requires at least one target');
        }

        foreach ($targets as $target) {
            if (! in_array($target->interface, [NotificationInterface::Admin, NotificationInterface::Lk], true)) {
                throw new RuntimeException("Unsupported notification interface: {$target->interface->value}");
            }
        }

        $firstFailure = null;

        foreach ($targets as $target) {
            if ($target->websocket_status === 'sent') {
                continue;
            }

            try {
                $this->broadcastTarget($notification, $notifiable, $target);
                $target->markWebSocketSent();
            } catch (Throwable $exception) {
                $target->markWebSocketFailed($exception->getMessage());
                $firstFailure ??= $exception;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    private function broadcastTarget(
        Notification $notification,
        object $notifiable,
        NotificationTarget $target,
    ): void {
        $interface = $target->interface->value;
        $data = $notification->data;
        $data['interface'] = $interface;

        $this->broadcasting->connection('reverb')->broadcast(
            ['private-App.Models.User.'.$notifiable->id.'.'.$interface],
            'notification.new',
            [
                'id' => $notification->id,
                'type' => $notification->type,
                'notification_type' => $notification->notification_type,
                'priority' => $notification->priority,
                'interface' => $interface,
                'data' => $data,
                'created_at' => $notification->created_at->toIso8601String(),
                'read_at' => $notification->read_at?->toIso8601String(),
            ]
        );
    }

    private function targets(Notification $notification): Collection
    {
        if ($notification->relationLoaded('targets')) {
            return $notification->targets;
        }

        return $notification->targets()->get();
    }
}
