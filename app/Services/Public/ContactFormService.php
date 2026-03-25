<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Models\ContactForm;
use App\Services\Notification\TelegramService;
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

        if ((bool) config('telegram.notifications.contact_forms')) {
            $telegramSent = $this->telegramService->sendContactFormNotification($contactForm);
        }

        if ($telegramSent) {
            $contactForm->markAsProcessed();
        }

        Log::info('Public contact form submitted', [
            'contact_form_id' => $contactForm->id,
            'email' => $contactForm->email,
            'page_source' => $contactForm->page_source,
            'company_role' => $contactForm->company_role,
            'company_size' => $contactForm->company_size,
            'telegram_sent' => $telegramSent,
        ]);

        return $contactForm->refresh();
    }
}
