<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rule;

class ContractPaymentsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()->current_organization_id;

        return [
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'contractor_id' => [
                'nullable',
                'integer',
                Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
            ],
            'status' => 'nullable|string|in:draft,active,completed,terminated,suspended',
            'work_type_category' => ['nullable', new Enum(ContractWorkTypeCategoryEnum::class)],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'show_overdue' => 'nullable|in:0,1,true,false',
            'show_with_debt' => 'nullable|in:0,1,true,false',
            'format' => 'nullable|string|in:json,excel,pdf',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.exists' => 'Указанный проект не найден',
            'contractor_id.exists' => 'Указанный подрядчик не найден',
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала',
            'status.in' => 'Недопустимый статус контракта',
            'format.in' => 'Недопустимый формат экспорта',
        ];
    }
}

