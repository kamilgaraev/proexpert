<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Channels;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use Throwable;

class WebSocketAnalyticsRecorder
{
    public function start(Notification $notification): NotificationAnalytics
    {
        return NotificationAnalytics::create([
            'notification_id' => $notification->id,
            'channel' => 'websocket',
            'status' => 'pending',
        ]);
    }

    public function markSent(NotificationAnalytics $analytics): void
    {
        $analytics->updateStatus('sent');
    }

    public function markFailed(NotificationAnalytics $analytics, Throwable $exception): void
    {
        $analytics->updateStatus('failed');
        $analytics->update(['error_message' => $exception->getMessage()]);
    }
}
