<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\EnterpriseInquiryData;
use App\Models\ContactForm;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class EnterpriseInquiryService
{
    public function __construct(
        private EnterpriseInquiryPayloadFactory $payloadFactory,
    ) {}

    public function create(User $user, int $organizationId, EnterpriseInquiryData $data, array $legalInput, \Illuminate\Http\Request $request): ContactForm
    {
        if (! filter_var($legalInput['consent_to_personal_data'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['consent_to_personal_data' => trans_message('public_contact.validation.consent_required')]);
        }
        app(\App\Services\Legal\LegalDocumentService::class)->assertAccepted($legalInput, ['contactConsent'], false);
        if (! $user->organizations()->whereKey($organizationId)->exists()) {
            throw new \Illuminate\Auth\Access\AuthorizationException(trans_message('legal.authority'));
        }
        return DB::transaction(function () use ($user, $organizationId, $data, $request): ContactForm {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organizationId);
            $existing = ContactForm::query()
                ->where('organization_id', $organizationId)
                ->where('page_source', 'lk-enterprise-inquiry')
                ->where('telegram_data->client_request_id', $data->clientRequestId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $contact = ContactForm::query()->create($this->payloadFactory->make($user, $organization, $data));
            app(\App\Services\Legal\LegalAcceptanceService::class)->record('contactConsent', 'enterprise_inquiry', (string) $contact->id, $request, $user, $organization);

            return $contact;
        });
    }
}
