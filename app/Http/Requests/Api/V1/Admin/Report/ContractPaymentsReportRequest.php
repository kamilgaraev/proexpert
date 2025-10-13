<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;

class ContractPaymentsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => 'nullable|integer|exists:projects,id',
            'contractor_id' => 'nullable|integer|exists:contractors,id',
            'status' => 'nullable|string|in:draft,active,completed,terminated,suspended',
            'work_type_category' => 'nullable|string|in:construction,design,supply,consulting,other',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'show_overdue' => 'nullable|boolean',
            'show_with_debt' => 'nullable|boolean',
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

