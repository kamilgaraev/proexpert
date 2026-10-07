<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Models\ContactForm;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
        if (! filter_var($payload['consent_to_personal_data'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['consent_to_personal_data' => trans_message('public_contact.validation.consent_required')]);
        }
        app(\App\Services\Legal\LegalDocumentService::class)->assertAccepted($payload, ['contactConsent']);
        $payload['consent_version'] = config('legal.version');
        $analyticsAllowed = ($payload['analytics_consent'] ?? false)
            && isset($payload['analytics_visitor_id'], $payload['analytics_receipt_id'])
            && app(\App\Services\Legal\AnalyticsConsentService::class)->active(['visitor_id' => $payload['analytics_visitor_id'], 'receipt_id' => $payload['analytics_receipt_id']]);
        if (! $analyticsAllowed) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
                unset($payload[$key]);
            }
        }
        unset($payload['legal_documents'], $payload['analytics_consent'], $payload['analytics_visitor_id'], $payload['analytics_receipt_id']);
        $payload['priority'] ??= ContactForm::PRIORITY_NORMAL;
        $payload['channel'] ??= ContactForm::CHANNEL_PUBLIC_FORM;
        $payload['last_activity_at'] ??= now();
        $payload['notification_delivery'] = [
            'pending' => true,
            'attempts' => 0,
            'next_attempt_at' => now()->toISOString(),
        ];

        $contactForm = DB::transaction(function () use ($payload): ContactForm {
            $contact = ContactForm::create($payload);
            app(\App\Services\Legal\LegalAcceptanceService::class)->record('contactConsent', 'public_contact', (string) $contact->id, request());

            return $contact;
        });
        $this->notifications->enqueue($contactForm);

        Log::info('Public contact form submitted', [
            'contact_form_id' => $contactForm->id,
            'page_source' => $contactForm->page_source,
        ]);

        return $contactForm->refresh();
    }
}
