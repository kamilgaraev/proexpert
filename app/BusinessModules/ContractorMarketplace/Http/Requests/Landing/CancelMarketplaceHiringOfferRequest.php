<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Requests\Landing;

use Illuminate\Foundation\Http\FormRequest;

class CancelMarketplaceHiringOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id;

        return $this->user() !== null
            && $organizationId !== null
            && $this->user()->belongsToOrganization((int) $organizationId);
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
