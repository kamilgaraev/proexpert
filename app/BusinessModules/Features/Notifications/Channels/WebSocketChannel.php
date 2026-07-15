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
    private readonly WebSocketAnalyticsRecorder $analyticsRecorder;

    public function __construct(
        private readonly Factory $broadcasting,
        ?WebSocketAnalyticsRecorder $analyticsRecorder = null,
    ) {
        $this->analyticsRecorder = $analyticsRecorder ?? new WebSocketAnalyticsRecorder;
    }

    public function send($notifiable, Notification $notification): bool
    {
        if (config('broadcasting.default') !== 'reverb') {
            throw new RuntimeException('WebSocket channel requires the Reverb broadcasting connection');
        }

        $analytics = $this->startAnalytics($notification);
        $deliveryFailure = null;

        try {
            $this->broadcastNotification($notification, $notifiable);
        } catch (Throwable $exception) {
            $deliveryFailure = $exception;
        }

        if ($deliveryFailure === null) {
            $this->markAnalyticsSent($analytics, $notification);

            Log::info('WebSocket notification broadcasted', [
                'notification_id' => $notification->id,
                'notifiable_id' => $notifiable->id,
            ]);

            return true;
        }

        $this->markAnalyticsFailed($analytics, $notification, $deliveryFailure);

        Log::warning('WebSocket notification failed', [
            'notification_id' => $notification->id,
            'notifiable_id' => $notifiable->id,
            'exception' => $deliveryFailure::class,
        ]);

        throw $deliveryFailure;
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
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
                $this->markTargetFailed($notification, $target, $exception);

                continue;
            }

            try {
                $target->markWebSocketSent();
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
                $this->logTargetStateFailure($notification, $target, 'sent', $exception);
                $this->markTargetFailed($notification, $target, $exception);
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
                'read_at' => $target->read_at?->toIso8601String(),
            ]
        );
    }

    private function markTargetFailed(
        Notification $notification,
        NotificationTarget $target,
        Throwable $deliveryFailure,
    ): void {
        try {
            $target->markWebSocketFailed($deliveryFailure->getMessage());
        } catch (Throwable $stateFailure) {
            $this->logTargetStateFailure($notification, $target, 'failed', $stateFailure);
        }
    }

    private function logTargetStateFailure(
        Notification $notification,
        NotificationTarget $target,
        string $transition,
        Throwable $exception,
    ): void {
        Log::warning('WebSocket target state persistence failed', [
            'notification_id' => $notification->id,
            'target_id' => $target->id,
            'interface' => $target->interface->value,
            'transition' => $transition,
            'exception' => $exception::class,
        ]);
    }

    private function startAnalytics(Notification $notification): ?NotificationAnalytics
    {
        try {
            return $this->analyticsRecorder->start($notification);
        } catch (Throwable $exception) {
            $this->logAnalyticsFailure($notification, 'start', $exception);

            return null;
        }
    }

    private function markAnalyticsSent(?NotificationAnalytics $analytics, Notification $notification): void
    {
        if ($analytics === null) {
            return;
        }

        try {
            $this->analyticsRecorder->markSent($analytics);
        } catch (Throwable $exception) {
            $this->logAnalyticsFailure($notification, 'sent', $exception);
        }
    }

    private function markAnalyticsFailed(
        ?NotificationAnalytics $analytics,
        Notification $notification,
        Throwable $deliveryFailure,
    ): void {
        if ($analytics === null) {
            return;
        }

        try {
            $this->analyticsRecorder->markFailed($analytics, $deliveryFailure);
        } catch (Throwable $exception) {
            $this->logAnalyticsFailure($notification, 'failed', $exception);
        }
    }

    private function logAnalyticsFailure(
        Notification $notification,
        string $transition,
        Throwable $exception,
    ): void {
        Log::warning('WebSocket analytics persistence failed', [
            'notification_id' => $notification->id,
            'transition' => $transition,
            'exception' => $exception::class,
        ]);
    }

    private function targets(Notification $notification): Collection
    {
        if ($notification->relationLoaded('targets')) {
            return $notification->targets;
        }

        return $notification->targets()->get();
    }
}
