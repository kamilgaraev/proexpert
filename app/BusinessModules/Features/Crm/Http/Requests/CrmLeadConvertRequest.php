<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CrmLeadConvertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'uuid'],
            'company' => ['nullable', 'array'],
            'company.name' => ['required_without:company_id', 'string', 'max:500'],
            'company.inn' => ['nullable', 'string', 'max:32'],
            'company.phone' => ['nullable', 'string', 'max:64'],
            'company.email' => ['nullable', 'email', 'max:255'],
            'contact_id' => ['nullable', 'uuid'],
            'contact' => ['nullable', 'array'],
            'contact.full_name' => ['nullable', 'string', 'max:500'],
            'contact.phone' => ['nullable', 'string', 'max:64'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'deal' => ['required', 'array'],
            'deal.title' => ['required', 'string', 'max:500'],
            'deal.amount' => ['nullable', 'numeric', 'min:0'],
            'deal.currency' => ['nullable', 'string', 'size:3'],
            'deal.pipeline_id' => ['nullable', 'uuid'],
            'deal.stage_id' => ['nullable', 'uuid'],
            'deal.pipeline_code' => ['nullable', 'string', 'max:64'],
            'deal.stage_code' => ['nullable', 'string', 'max:64'],
            'deal.expected_close_at' => ['nullable', 'date'],
            'deal.owner_user_id' => ['nullable', 'integer'],
        ];
    }
}
