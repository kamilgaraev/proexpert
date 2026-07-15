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

    public function create(User $user, int $organizationId, EnterpriseInquiryData $data): ContactForm
    {
        return DB::transaction(function () use ($user, $organizationId, $data): ContactForm {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organizationId);
            $existing = ContactForm::query()
                ->where('organization_id', $organizationId)
                ->where('page_source', 'lk-enterprise-inquiry')
                ->where('telegram_data->client_request_id', $data->clientRequestId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return ContactForm::query()->create($this->payloadFactory->make($user, $organization, $data));
        });
    }
}
