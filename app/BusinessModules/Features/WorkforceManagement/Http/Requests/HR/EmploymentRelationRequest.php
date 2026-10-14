<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

final class EmploymentRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'relation_type' => ['required', 'in:employment,civil'],
            'employment_mode' => ['nullable', 'in:main,internal_secondary,external_secondary,part_time'],
            'contract_number' => ['required', 'string', 'max:120'], 'contract_date' => ['required', 'date_format:Y-m-d'],
            'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'job_share' => ['nullable', 'numeric', 'gt:0', 'lte:1'], 'timezone' => ['nullable', 'timezone'],
            'weekly_norm_hours' => ['nullable', 'numeric', 'gt:0', 'lte:40'],
            'summed_accounting_period' => ['nullable', 'in:month,quarter,year'], 'calendar_profile' => ['nullable', 'string', 'max:80'],
            'supersedes_id' => ['nullable', 'integer'], 'event_type' => ['required', 'in:hire,change,transfer,reconcile'],
            'basis_reference' => ['required', 'string', 'max:255'], 'basis_document_id' => ['nullable', 'integer'],
        ];
    }
}
