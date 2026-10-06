<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Jobs\SendPublicContactNotification;
use App\Mail\PublicContactFormMail;
use App\Models\ContactForm;
use App\Services\Notification\TelegramService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactFormNotificationService
{
    public function enqueue(ContactForm $contactForm): void
    {
        try {
            SendPublicContactNotification::dispatch((int) $contactForm->id)->afterCommit();
        } catch (Throwable $exception) {
            Log::error('Public contact notification enqueue failed', [
                'contact_form_id' => $contactForm->id,
                'exception_class' => $exception::class,
            ]);
        }
    }

    public function deliver(ContactForm $contactForm): bool
    {
        $delivery = $contactForm->notification_delivery;
        if (! is_array($delivery) || ! ($delivery['pending'] ?? false)) {
            return true;
        }

        if ($contactForm->channel !== ContactForm::CHANNEL_PUBLIC_FORM || $contactForm->status === ContactForm::STATUS_CANCELLED) {
            $contactForm->update(['notification_delivery' => array_replace($delivery, ['pending' => false])]);

            return true;
        }

        $delivery['attempts'] = ((int) ($delivery['attempts'] ?? 0)) + 1;
        $delivery['next_attempt_at'] = now()->addMinutes(5)->toISOString();
        $contactForm->update(['notification_delivery' => $delivery]);

        $telegramEnabled = (bool) config('legal.telegram_contact_notifications', false) && (bool) config('telegram.notifications.contact_forms');
        $recipients = $this->notificationRecipients();
        $telegramSent = ! empty($contactForm->telegram_data['sent_at']);
        $emailSent = ! empty($delivery['email_sent_at']);

        if ($telegramEnabled && ! $telegramSent) {
            try {
                $telegramSent = app(TelegramService::class)->sendContactFormNotification($contactForm);
            } catch (Throwable $exception) {
                $this->logFailure($contactForm, 'telegram', $exception);
            }
        }

        if ($recipients !== [] && ! $emailSent) {
            try {
                Mail::to($recipients)->send(new PublicContactFormMail($contactForm));
                $emailSent = true;
                $delivery['email_sent_at'] = now()->toISOString();
                $contactForm->update(['notification_delivery' => $delivery]);
            } catch (Throwable $exception) {
                $this->logFailure($contactForm, 'email', $exception);
            }
        }

        if (($telegramSent || $emailSent) && ! $contactForm->is_processed && $contactForm->status === ContactForm::STATUS_NEW) {
            $contactForm->markAsProcessed();
        }

        $complete = ($telegramEnabled || $recipients !== [])
            && (! $telegramEnabled || $telegramSent)
            && ($recipients === [] || $emailSent);
        $exhausted = $delivery['attempts'] >= 10 && ! $complete;
        $delivery['pending'] = ! $complete && ! $exhausted;
        $delivery['failed_at'] = $exhausted ? now()->toISOString() : null;
        $contactForm->update(['notification_delivery' => $delivery]);

        if ($exhausted) {
            Log::error('Public contact notification retries exhausted', ['contact_form_id' => $contactForm->id]);
        }

        return $complete || $exhausted;
    }

    protected function notificationRecipients(): array
    {
        $recipients = config('services.public_contact.recipients', []);

        return is_array($recipients) ? array_values(array_filter(array_map(
            static fn (mixed $recipient): string => is_string($recipient) ? trim($recipient) : '',
            $recipients,
        ))) : [];
    }

    private function logFailure(ContactForm $contactForm, string $channel, Throwable $exception): void
    {
        Log::error('Public contact notification failed', [
            'contact_form_id' => $contactForm->id,
            'channel' => $channel,
            'exception_class' => $exception::class,
        ]);
    }
}
