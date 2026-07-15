<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Jobs;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Notification $notification;

    public int $tries;

    public int $backoff;

    public function __construct(Notification $notification)
    {
        $this->notification = $notification;

        $priorityConfig = config("notifications.priorities.{$notification->priority}");
        $this->tries = $priorityConfig['retry_times'] ?? 3;
        $this->backoff = $priorityConfig['retry_after'] ?? 300;
    }

    public function handle(NotificationService $notificationService): void
    {
        Log::info('Processing notification job', [
            'notification_id' => $this->notification->id,
            'channels' => $this->notification->channels,
            'attempt' => $this->attempts(),
        ]);

        $firstFailure = null;
        $deliveryStatus = $this->notification->delivery_status ?? [];

        foreach ($this->notification->channels as $channel) {
            if ($this->shouldSkipChannel($channel, $deliveryStatus)) {
                continue;
            }

            try {
                $success = $notificationService->sendViaChannel($this->notification, $channel);

                if (! $success) {
                    throw new RuntimeException("Notification channel {$channel} reported a failed delivery");
                }

                Log::info('Channel delivery attempt', [
                    'notification_id' => $this->notification->id,
                    'channel' => $channel,
                    'success' => $success,
                    'attempt' => $this->attempts(),
                ]);

            } catch (Throwable $e) {
                Log::error('Channel delivery exception', [
                    'notification_id' => $this->notification->id,
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);

                $firstFailure ??= $e;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    private function shouldSkipChannel(string $channel, array $deliveryStatus): bool
    {
        if (($deliveryStatus[$channel] ?? null) !== 'sent') {
            return false;
        }

        if ($channel !== 'websocket') {
            return true;
        }

        if (! $this->notification->relationLoaded('targets')) {
            $this->notification->setRelation('targets', $this->notification->targets()->get());
        }

        $targets = $this->notification->targets;

        return $targets->isNotEmpty() && $targets->every(
            static fn ($target): bool => in_array(
                $target->interface,
                [NotificationInterface::Admin, NotificationInterface::Lk],
                true,
            ) && $target->websocket_status === 'sent'
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Notification job failed permanently', [
            'notification_id' => $this->notification->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
