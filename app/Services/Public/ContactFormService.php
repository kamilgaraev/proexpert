<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Models\ContactForm;
use Illuminate\Support\Facades\Log;

class ContactFormService
{
    public function __construct(
        protected ContactFormNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(array $payload): ContactForm
    {
        $payload['priority'] ??= ContactForm::PRIORITY_NORMAL;
        $payload['channel'] ??= ContactForm::CHANNEL_PUBLIC_FORM;
        $payload['last_activity_at'] ??= now();
        $payload['notification_delivery'] = [
            'pending' => true,
            'attempts' => 0,
            'next_attempt_at' => now()->toISOString(),
        ];

        $contactForm = ContactForm::create($payload);
        $this->notifications->enqueue($contactForm);

        Log::info('Public contact form submitted', [
            'contact_form_id' => $contactForm->id,
            'page_source' => $contactForm->page_source,
        ]);

        return $contactForm->refresh();
    }
}
