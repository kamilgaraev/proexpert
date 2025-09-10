<?php

namespace App\Services\Notification;

use App\Models\ContactForm;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class TelegramService
{
    protected string $botToken;
    protected string $chatId;
    protected string $baseUrl;

    public function __construct()
    {
        $this->botToken = config('telegram.bot_token');
        $this->chatId = config('telegram.chat_id');
        $this->baseUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    public function sendContactFormNotification(ContactForm $contactForm): bool
    {
        if (empty($this->botToken) || empty($this->chatId)) {
            Log::warning('Telegram credentials not configured');
            return false;
        }

        $message = $this->formatContactFormMessage($contactForm);
        
        return $this->sendMessage($message, $contactForm);
    }

    protected function formatContactFormMessage(ContactForm $contactForm): string
    {
        $message = "🔔 *Новая заявка с сайта*\n\n";
        $message .= "👤 **Имя:** {$contactForm->name}\n";
        $message .= "📧 **Email:** {$contactForm->email}\n";
        
        if ($contactForm->phone) {
            $message .= "📞 **Телефон:** {$contactForm->phone}\n";
        }
        
        if ($contactForm->company) {
            $message .= "🏢 **Компания:** {$contactForm->company}\n";
        }
        
        $message .= "📋 **Тема:** {$contactForm->subject}\n";
        $message .= "💬 **Сообщение:**\n{$contactForm->message}\n\n";
        $message .= "🕐 **Дата:** " . $contactForm->created_at->format('d.m.Y H:i') . "\n";
        $message .= "🆔 **ID заявки:** #{$contactForm->id}";

        return $message;
    }

    protected function sendMessage(string $message, ContactForm $contactForm): bool
    {
        try {
            $response = Http::timeout(30)->post($this->baseUrl . '/sendMessage', [
                'chat_id' => $this->chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                $contactForm->update([
                    'telegram_data' => [
                        'message_id' => $responseData['result']['message_id'] ?? null,
                        'sent_at' => now()->toISOString(),
                        'chat_id' => $this->chatId,
                    ]
                ]);

                Log::info('Telegram notification sent successfully', [
                    'contact_form_id' => $contactForm->id,
                    'telegram_message_id' => $responseData['result']['message_id'] ?? null,
                ]);

                return true;
            }

            Log::error('Failed to send Telegram notification', [
                'contact_form_id' => $contactForm->id,
                'response_status' => $response->status(),
                'response_body' => $response->body(),
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('Telegram API error', [
                'contact_form_id' => $contactForm->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

}
