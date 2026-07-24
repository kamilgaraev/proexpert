<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\Billing;

use Illuminate\Foundation\Http\FormRequest;

class CommercialCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_package_slugs' => ['present', 'array', 'prohibited_if:full_suite,true'],
            'target_package_slugs.*' => ['required', 'string', 'max:100', 'distinct'],
            'current_package_slugs' => ['present', 'array'],
            'current_package_slugs.*' => ['required', 'string', 'max:100', 'distinct'],
            'full_suite' => ['required', 'boolean'],
            'resources' => ['sometimes', 'array', 'max:20'],
            'resources.*.slug' => ['required', 'string', 'max:100'],
            'resources.*.quantity' => ['required', 'numeric', 'min:0'],
            'quote_version' => ['required', 'integer', 'min:1'],
            'resource_quote_version' => ['sometimes', 'integer', 'min:1'],
            'client_idempotency_key' => ['required', 'string', 'min:36', 'max:100', 'regex:/^[A-Za-z0-9-]+$/'],
            'auto_renew_consent' => ['required', 'boolean'],
            'use_balance' => ['required', 'boolean'],
            'current_period_start_at' => ['prohibited'],
            'current_period_end_at' => ['prohibited'],
        ];
    }
}
