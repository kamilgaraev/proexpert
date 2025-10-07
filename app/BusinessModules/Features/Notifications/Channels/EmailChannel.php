<?php

namespace App\BusinessModules\Features\Notifications\Channels;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use App\BusinessModules\Features\Notifications\Services\TemplateRenderer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailChannel
{
    protected TemplateRenderer $templateRenderer;

    public function __construct(TemplateRenderer $templateRenderer)
    {
        $this->templateRenderer = $templateRenderer;
    }

    public function send($notifiable, Notification $notification): bool
    {
        try {
            $analytics = $this->createAnalytics($notification);
            
            $email = $notifiable->email ?? $notifiable->user?->email;
            
            if (!$email) {
                $this->markFailed($analytics, 'No email address');
                return false;
            }

            $template = $this->templateRenderer->getTemplate(
                $notification->type,
                'email',
                $notification->organization_id
            );

            if (!$template) {
                $this->markFailed($analytics, 'Template not found');
                return false;
            }

            $data = array_merge(
                $notification->data,
                ['notification_id' => $notification->id]
            );

            $subject = $this->templateRenderer->renderString($template->subject, $data);
            $htmlContent = $this->templateRenderer->render($template, $data);
            
            $htmlContent = $this->injectTrackingPixel($htmlContent, $analytics->tracking_id);
            $htmlContent = $this->wrapLinksWithTracking($htmlContent, $analytics->tracking_id);

            Mail::html($htmlContent, function ($message) use ($email, $subject) {
                $message->to($email)
                    ->subject($subject);
            });

            $analytics->updateStatus('sent');
            
            Log::info('Email notification sent', [
                'notification_id' => $notification->id,
                'email' => $email,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Email notification failed', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (isset($analytics)) {
                $this->markFailed($analytics, $e->getMessage());
            }

            return false;
        }
    }

    protected function createAnalytics(Notification $notification): NotificationAnalytics
    {
        return NotificationAnalytics::create([
            'notification_id' => $notification->id,
            'channel' => 'email',
            'status' => 'pending',
            'tracking_id' => $this->generateTrackingId(),
        ]);
    }

    protected function markFailed(NotificationAnalytics $analytics, string $message): void
    {
        $analytics->updateStatus('failed');
        $analytics->update(['error_message' => $message]);
    }

    protected function generateTrackingId(): string
    {
        return 'email_' . uniqid() . '_' . bin2hex(random_bytes(8));
    }

    protected function injectTrackingPixel(string $html, string $trackingId): string
    {
        if (!config('notifications.analytics.enabled') || !config('notifications.channels.email.tracking.opens')) {
            return $html;
        }

        $trackingUrl = route('notifications.track.open', ['tracking_id' => $trackingId]);
        $pixel = '<img src="' . $trackingUrl . '" width="1" height="1" alt="" style="display:none;" />';

        if (stripos($html, '</body>') !== false) {
            return str_ireplace('</body>', $pixel . '</body>', $html);
        }

        return $html . $pixel;
    }

    protected function wrapLinksWithTracking(string $html, string $trackingId): string
    {
        if (!config('notifications.analytics.enabled') || !config('notifications.channels.email.tracking.clicks')) {
            return $html;
        }

        return preg_replace_callback(
            '/<a\s+([^>]*href=["\'])([^"\']+)(["\'][^>]*)>/i',
            function ($matches) use ($trackingId) {
                $originalUrl = $matches[2];
                
                if (strpos($originalUrl, 'notifications.track') !== false) {
                    return $matches[0];
                }

                $trackedUrl = route('notifications.track.click', [
                    'tracking_id' => $trackingId,
                    'url' => urlencode($originalUrl),
                ]);

                return '<a ' . $matches[1] . $trackedUrl . $matches[3] . '>';
            },
            $html
        );
    }
}

