<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrmLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'company_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'owner_user_id' => ['nullable', 'integer'],
            'source_id' => ['nullable', 'uuid'],
            'source_ref_type' => ['nullable', 'string', 'max:64'],
            'source_ref_id' => ['nullable', 'string', 'max:128'],
            'title' => [$required, 'string', 'max:500'],
            'status' => ['nullable', 'string', Rule::in(['new', 'qualified', 'in_work', 'lost', 'converted', 'archived'])],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'estimated_amount' => ['nullable', 'numeric', 'min:0'],
            'expected_start_date' => ['nullable', 'date'],
            'need_description' => ['nullable', 'string', 'max:4000'],
            'utm' => ['nullable', 'array'],
            'raw_source_data' => ['nullable', 'array'],
            'lost_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
