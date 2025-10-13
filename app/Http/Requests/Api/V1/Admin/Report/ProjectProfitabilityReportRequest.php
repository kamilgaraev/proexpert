<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;

class ProjectProfitabilityReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => 'nullable|integer|exists:projects,id',
            'status' => 'nullable|string|in:planning,active,on_hold,completed,cancelled',
            'customer' => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'min_profitability' => 'nullable|numeric',
            'max_profitability' => 'nullable|numeric',
            'show_losses_only' => 'nullable|boolean',
            'include_labor_costs' => 'nullable|boolean',
            'format' => 'nullable|string|in:json,excel,pdf',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.exists' => 'Указанный проект не найден',
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала',
            'status.in' => 'Недопустимый статус проекта',
            'format.in' => 'Недопустимый формат экспорта',
        ];
    }
}

