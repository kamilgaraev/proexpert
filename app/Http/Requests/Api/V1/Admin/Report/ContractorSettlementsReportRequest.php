<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractorSettlementsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()->current_organization_id;

        return [
            'contractor_id' => [
                'nullable',
                'integer',
                Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
            ],
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'settlement_status' => 'nullable|string|in:all,has_debt,has_prepayment,settled',
            'min_debt_amount' => 'nullable|numeric|min:0',
            'format' => 'nullable|string|in:json,excel,pdf',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'contractor_id.exists' => 'Указанный подрядчик не найден',
            'project_id.exists' => 'Указанный проект не найден',
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала',
            'settlement_status.in' => 'Недопустимый статус расчетов',
            'format.in' => 'Недопустимый формат экспорта',
        ];
    }
}

