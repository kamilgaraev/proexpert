<?php

namespace App\Services\Notification;

use App\Models\ContactForm;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class TelegramService
{
    protected string $botToken;
    protected string $chatId;
    protected string $baseUrl;
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
        $this->botToken = config('telegram.bot_token');
        $this->chatId = config('telegram.chat_id');
        $this->baseUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    public function sendContactFormNotification(ContactForm $contactForm): bool
    {
        if (empty($this->botToken) || empty($this->chatId)) {
            // TECHNICAL: Конфигурация Telegram не настроена
            $this->logging->technical('telegram.configuration.missing', [
                'contact_form_id' => $contactForm->id,
                'has_bot_token' => !empty($this->botToken),
                'has_chat_id' => !empty($this->chatId),
                'configuration_issue' => true
            ], 'warning');
            
            return false;
        }

        // BUSINESS: Начало отправки Telegram уведомления
        $this->logging->business('telegram.notification.started', [
            'contact_form_id' => $contactForm->id,
            'contact_email' => $contactForm->email,
            'contact_name' => $contactForm->name,
            'subject' => $contactForm->subject,
            'notification_type' => 'contact_form'
        ]);

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

        if ($contactForm->company_role) {
            $message .= "🧩 **Роль компании:** {$contactForm->company_role}\n";
        }

        if ($contactForm->company_size) {
            $message .= "🏗 **Масштаб:** {$contactForm->company_size}\n";
        }
        
        $message .= "📋 **Тема:** {$contactForm->subject}\n";
        $message .= "💬 **Сообщение:**\n{$contactForm->message}\n\n";

        $message .= "🔐 **PD consent:** " . ($contactForm->consent_to_personal_data ? 'yes' : 'no') . "\n";

        if ($contactForm->consent_version) {
            $message .= "📝 **Consent version:** {$contactForm->consent_version}\n";
        }

        if ($contactForm->page_source) {
            $message .= "🧭 **Page source:** {$contactForm->page_source}\n";
        }

        $utmPayload = array_filter([
            'utm_source' => $contactForm->utm_source,
            'utm_medium' => $contactForm->utm_medium,
            'utm_campaign' => $contactForm->utm_campaign,
            'utm_term' => $contactForm->utm_term,
            'utm_content' => $contactForm->utm_content,
        ]);

        if ($utmPayload !== []) {
            $message .= "📊 **UTM:** " . implode(', ', array_map(
                static fn (string $key, string $value): string => "{$key}={$value}",
                array_keys($utmPayload),
                array_values($utmPayload)
            )) . "\n";
        }

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

                // BUSINESS: Telegram уведомление успешно отправлено
                $this->logging->business('telegram.notification.sent', [
                    'contact_form_id' => $contactForm->id,
                    'telegram_message_id' => $responseData['result']['message_id'] ?? null,
                    'chat_id' => $this->chatId,
                    'contact_email' => $contactForm->email,
                    'notification_type' => 'contact_form'
                ]);

                // TECHNICAL: Telegram API успешный ответ для мониторинга интеграций
                $this->logging->technical('telegram.api.success', [
                    'contact_form_id' => $contactForm->id,
                    'telegram_message_id' => $responseData['result']['message_id'] ?? null,
                    'response_time_ms' => $response->transferStats?->getTransferTime() * 1000 ?? null,
                    'api_endpoint' => 'sendMessage'
                ]);

                return true;
            }

            // TECHNICAL: Неудачная отправка Telegram уведомления - проблема интеграции
            $this->logging->technical('telegram.api.failed', [
                'contact_form_id' => $contactForm->id,
                'response_status' => $response->status(),
                'response_body' => $response->body(),
                'api_endpoint' => 'sendMessage',
                'integration_failure' => true
            ], 'error');

            // BUSINESS: Неудачная отправка уведомления - может влиять на бизнес-процесс
            $this->logging->business('telegram.notification.failed', [
                'contact_form_id' => $contactForm->id,
                'contact_email' => $contactForm->email,
                'failure_reason' => 'api_error',
                'response_status' => $response->status()
            ], 'warning');

            return false;

        } catch (\Exception $e) {
            // TECHNICAL: Критическая ошибка Telegram API
            $this->logging->technical('telegram.api.exception', [
                'contact_form_id' => $contactForm->id,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'api_endpoint' => 'sendMessage'
            ], 'error');

            // BUSINESS: Системная ошибка при отправке уведомления
            $this->logging->business('telegram.notification.exception', [
                'contact_form_id' => $contactForm->id,
                'contact_email' => $contactForm->email,
                'failure_reason' => 'system_exception',
                'error_message' => $e->getMessage()
            ], 'error');

            return false;
        }
    }

}
