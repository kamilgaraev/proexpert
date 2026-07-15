<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class CommercialContourScheduleRequest extends FormRequest
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
            'quote_version' => ['required', 'integer', 'min:1'],
            'client_idempotency_key' => [
                'required', 'string', 'min:36', 'max:100', 'regex:/^[A-Za-z0-9-]+$/',
            ],
        ];
    }
}
