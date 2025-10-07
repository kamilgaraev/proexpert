<?php

namespace App\BusinessModules\Features\Notifications\Channels;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use Illuminate\Support\Facades\Log;

class InAppChannel
{
    public function send($notifiable, Notification $notification): bool
    {
        try {
            $analytics = NotificationAnalytics::create([
                'notification_id' => $notification->id,
                'channel' => 'in_app',
                'status' => 'pending',
            ]);

            $notification->update([
                'delivery_status' => array_merge(
                    $notification->delivery_status ?? [],
                    ['in_app' => 'delivered']
                )
            ]);

            $analytics->updateStatus('delivered');

            Log::info('In-App notification stored', [
                'notification_id' => $notification->id,
                'notifiable_id' => $notifiable->id,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('In-App notification failed', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

