<?php

namespace App\BusinessModules\Features\Notifications\Channels;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use App\BusinessModules\Features\Notifications\Events\NotificationBroadcast;
use Illuminate\Support\Facades\Log;

class WebSocketChannel
{
    public function send($notifiable, Notification $notification): bool
    {
        try {
            if (config('broadcasting.default') !== 'reverb') {
                Log::warning('WebSocket channel disabled: broadcasting driver is not reverb');
                return false;
            }

            $analytics = NotificationAnalytics::create([
                'notification_id' => $notification->id,
                'channel' => 'websocket',
                'status' => 'pending',
            ]);

            Log::info('[WebSocket] Before event()', [
                'notification_id' => $notification->id,
                'notifiable_id' => $notifiable->id,
                'broadcast_driver' => config('broadcasting.default'),
            ]);

            event(new NotificationBroadcast($notification, $notifiable));

            Log::info('[WebSocket] After event()', [
                'notification_id' => $notification->id,
            ]);

            $analytics->updateStatus('sent');

            Log::info('WebSocket notification broadcasted', [
                'notification_id' => $notification->id,
                'notifiable_id' => $notifiable->id,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('WebSocket notification failed', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);

            if (isset($analytics)) {
                $analytics->updateStatus('failed');
                $analytics->update(['error_message' => $e->getMessage()]);
            }

            return false;
        }
    }
}

