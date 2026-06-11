<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrmDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'uuid'],
            'primary_contact_id' => ['nullable', 'uuid'],
            'lead_id' => ['nullable', 'uuid'],
            'owner_user_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'pipeline_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', 'uuid'],
            'source_id' => ['nullable', 'uuid'],
            'title' => [$required, 'string', 'max:500'],
            'pipeline_code' => ['nullable', 'string', 'max:64'],
            'stage_code' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', Rule::in(['open', 'won', 'lost', 'archived'])],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_at' => ['nullable', 'date'],
            'lost_reason' => ['nullable', 'string', 'max:1000'],
            'next_activity_at' => ['nullable', 'date'],
            'custom_fields' => ['nullable', 'array'],
        ];
    }
}
