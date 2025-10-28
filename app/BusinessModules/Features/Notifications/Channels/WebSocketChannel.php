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

            Log::info('[WebSocket] Sending HTTP to Reverb', [
                'notification_id' => $notification->id,
                'notifiable_id' => $notifiable->id,
            ]);

            $this->sendToReverbViaHttp($notification, $notifiable);

            Log::info('[WebSocket] Sent to Reverb', [
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

    protected function sendToReverbViaHttp(Notification $notification, $notifiable): void
    {
        $appId = config('broadcasting.connections.reverb.app_id');
        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');
        $host = config('broadcasting.connections.reverb.options.host');
        $port = config('broadcasting.connections.reverb.options.port');
        $scheme = config('broadcasting.connections.reverb.options.scheme');

        $channel = 'private-App.Models.User.' . $notifiable->id;
        $event = 'notification.new';
        $data = json_encode([
            'id' => $notification->id,
            'type' => $notification->type,
            'notification_type' => $notification->notification_type,
            'priority' => $notification->priority,
            'data' => $notification->data,
            'created_at' => $notification->created_at->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
        ]);

        $body = json_encode([
            'name' => $event,
            'channels' => [$channel],
            'data' => $data,
        ]);

        $path = "/apps/{$appId}/events";
        $timestamp = time();
        $bodyMd5 = md5($body);

        $stringToSign = "POST\n{$path}\nauth_key={$key}&auth_timestamp={$timestamp}&auth_version=1.0&body_md5={$bodyMd5}";
        $authSignature = hash_hmac('sha256', $stringToSign, $secret);

        $url = "{$scheme}://{$host}:{$port}{$path}";

        $response = \Illuminate\Support\Facades\Http::timeout(5)
            ->withHeaders([
                'X-Auth-Key' => $key,
                'X-Auth-Timestamp' => $timestamp,
                'X-Auth-Version' => '1.0',
                'X-Body-MD5' => $bodyMd5,
                'X-Auth-Signature' => $authSignature,
            ])
            ->post($url, [
                'name' => $event,
                'channels' => [$channel],
                'data' => $data,
            ]);

        Log::info('[WebSocket] HTTP response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            Log::error('[WebSocket] Reverb HTTP failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}

