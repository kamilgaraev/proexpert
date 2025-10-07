<?php

namespace App\BusinessModules\Features\Notifications\Channels;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use App\BusinessModules\Features\Notifications\Services\TemplateRenderer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramChannel
{
    protected TemplateRenderer $templateRenderer;
    protected string $botToken;
    protected string $baseUrl;

    public function __construct(TemplateRenderer $templateRenderer)
    {
        $this->templateRenderer = $templateRenderer;
        $this->botToken = config('telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    public function send($notifiable, Notification $notification): bool
    {
        try {
            $analytics = $this->createAnalytics($notification);

            $chatId = $this->getChatId($notifiable);

            if (!$chatId) {
                $this->markFailed($analytics, 'No Telegram chat ID');
                return false;
            }

            $template = $this->templateRenderer->getTemplate(
                $notification->type,
                'telegram',
                $notification->organization_id
            );

            if (!$template) {
                $this->markFailed($analytics, 'Template not found');
                return false;
            }

            $message = $this->templateRenderer->render($template, $notification->data);

            $response = Http::timeout(30)->post("{$this->baseUrl}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => false,
            ]);

            if ($response->successful() && $response->json('ok')) {
                $analytics->updateStatus('sent');
                $analytics->update(['metadata' => array_merge(
                    $analytics->metadata ?? [],
                    ['telegram_message_id' => $response->json('result.message_id')]
                )]);

                Log::info('Telegram notification sent', [
                    'notification_id' => $notification->id,
                    'chat_id' => $chatId,
                ]);

                return true;
            }

            $errorMessage = $response->json('description', 'Unknown error');
            $this->markFailed($analytics, $errorMessage);

            Log::error('Telegram notification failed', [
                'notification_id' => $notification->id,
                'error' => $errorMessage,
                'response' => $response->json(),
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('Telegram notification exception', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);

            if (isset($analytics)) {
                $this->markFailed($analytics, $e->getMessage());
            }

            return false;
        }
    }

    protected function getChatId($notifiable): ?string
    {
        if (is_string($notifiable)) {
            return $notifiable;
        }

        if (isset($notifiable->telegram_chat_id)) {
            return $notifiable->telegram_chat_id;
        }

        if (isset($notifiable->settings['telegram_chat_id'])) {
            return $notifiable->settings['telegram_chat_id'];
        }

        return config('telegram.chat_id');
    }

    protected function createAnalytics(Notification $notification): NotificationAnalytics
    {
        return NotificationAnalytics::create([
            'notification_id' => $notification->id,
            'channel' => 'telegram',
            'status' => 'pending',
        ]);
    }

    protected function markFailed(NotificationAnalytics $analytics, string $message): void
    {
        $analytics->updateStatus('failed');
        $analytics->update(['error_message' => $message]);
    }
}

