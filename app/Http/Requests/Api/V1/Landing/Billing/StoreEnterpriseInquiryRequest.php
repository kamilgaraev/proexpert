<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnterpriseInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'uuid'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'company_size' => ['required', Rule::in(['up_to_50', '51_200', '201_500', '501_1000', '1000_plus'])],
            'preferred_contact' => ['required', Rule::in(['phone', 'email', 'messenger'])],
            'needs' => ['required', 'array', 'min:1', 'max:6'],
            'needs.*' => ['required', 'distinct', Rule::in([
                'multi_organization',
                'integrations',
                'access_control',
                'implementation',
                'personal_configuration',
                'priority_support',
            ])],
            'comment' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
