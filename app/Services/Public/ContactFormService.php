<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Mail\PublicContactFormMail;
use App\Models\ContactForm;
use App\Services\Notification\TelegramService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ContactFormService
{
    public function __construct(
        protected TelegramService $telegramService,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function submit(array $payload): ContactForm
    {
        $contactForm = ContactForm::create($payload);
        $telegramSent = false;
        $emailSent = false;

        if ((bool) config('telegram.notifications.contact_forms')) {
            $telegramSent = $this->telegramService->sendContactFormNotification($contactForm);
        }

        $notificationRecipients = $this->notificationRecipients();

        if ($notificationRecipients !== []) {
            try {
                Mail::to($notificationRecipients)->send(new PublicContactFormMail($contactForm));
                $emailSent = true;
            } catch (\Throwable $exception) {
                Log::error('Public contact form email notification failed', [
                    'contact_form_id' => $contactForm->id,
                    'error' => $exception->getMessage(),
                    'recipients' => $notificationRecipients,
                ]);
            }
        }

        if ($telegramSent || $emailSent) {
            $contactForm->markAsProcessed();
        }

        Log::info('Public contact form submitted', [
            'contact_form_id' => $contactForm->id,
            'email' => $contactForm->email,
            'page_source' => $contactForm->page_source,
            'company_role' => $contactForm->company_role,
            'company_size' => $contactForm->company_size,
            'telegram_sent' => $telegramSent,
            'email_sent' => $emailSent,
            'notification_recipients' => $notificationRecipients,
        ]);

        return $contactForm->refresh();
    }

    /**
     * @return list<string>
     */
    protected function notificationRecipients(): array
    {
        $recipients = config('services.public_contact.recipients', []);

        if (! is_array($recipients)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $recipient): string => is_string($recipient) ? trim($recipient) : '',
            $recipients
        )));
    }
}
