<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class CommercialQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_package_slugs' => ['present', 'array'],
            'target_package_slugs.*' => ['required', 'string', 'max:100', 'distinct'],
            'full_suite' => ['required', 'boolean'],
            'billing_period' => ['sometimes', 'string', 'in:30_days,6_months,12_months'],
            'renew_subscription' => ['sometimes', 'boolean'],
            'resources' => ['sometimes', 'array', 'max:20'],
            'resources.*.slug' => ['required', 'string', 'max:100'],
            'resources.*.quantity' => ['required', 'numeric', 'min:0'],
            'current_package_slugs' => ['prohibited'],
            'current_period_start_at' => ['prohibited'],
            'current_period_end_at' => ['prohibited'],
        ];
    }
}
