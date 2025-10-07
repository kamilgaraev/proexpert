<?php

namespace App\BusinessModules\Features\Notifications\Jobs;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Notification $notification;

    public int $tries;
    public int $retryAfter;

    public function __construct(Notification $notification)
    {
        $this->notification = $notification;

        $priorityConfig = config("notifications.priorities.{$notification->priority}");
        $this->tries = $priorityConfig['retry_times'] ?? 3;
        $this->retryAfter = $priorityConfig['retry_after'] ?? 300;
    }

    public function handle(NotificationService $notificationService): void
    {
        Log::info('Processing notification job', [
            'notification_id' => $this->notification->id,
            'channels' => $this->notification->channels,
            'attempt' => $this->attempts(),
        ]);

        foreach ($this->notification->channels as $channel) {
            try {
                $success = $notificationService->sendViaChannel($this->notification, $channel);

                Log::info('Channel delivery attempt', [
                    'notification_id' => $this->notification->id,
                    'channel' => $channel,
                    'success' => $success,
                    'attempt' => $this->attempts(),
                ]);

            } catch (\Exception $e) {
                Log::error('Channel delivery exception', [
                    'notification_id' => $this->notification->id,
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);

                if ($this->attempts() >= $this->tries) {
                    $this->fail($e);
                }
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Notification job failed permanently', [
            'notification_id' => $this->notification->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(24);
    }
}

