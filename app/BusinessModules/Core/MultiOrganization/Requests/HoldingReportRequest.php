<?php

namespace App\BusinessModules\Core\MultiOrganization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HoldingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'organization_ids' => 'nullable|array',
            'organization_ids.*' => 'integer|exists:organizations,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'export_format' => 'nullable|in:csv,excel,xlsx',
            'sort_by' => 'nullable|string',
            'sort_direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:1000',
            'page' => 'nullable|integer|min:1',
        ];

        if ($this->routeIs('*.projects-summary')) {
            $rules = array_merge($rules, [
                'status' => 'nullable|in:planning,active,completed,on_hold,cancelled',
                'include_archived' => 'nullable|boolean',
                'min_budget' => 'nullable|numeric|min:0',
                'max_budget' => 'nullable|numeric|min:0',
                'customer' => 'nullable|string|max:255',
            ]);
        }

        if ($this->routeIs('*.contracts-summary')) {
            $rules = array_merge($rules, [
                'status' => 'nullable|in:draft,active,completed,terminated,suspended',
                'contractor_ids' => 'nullable|array',
                'contractor_ids.*' => 'integer|exists:contractors,id',
                'project_id' => 'nullable|integer|exists:projects,id',
                'min_amount' => 'nullable|numeric|min:0',
                'max_amount' => 'nullable|numeric|min:0',
                'work_type_category' => 'nullable|string',
                'include_child_contracts' => 'nullable|boolean',
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'organization_ids.array' => 'Список организаций должен быть массивом',
            'organization_ids.*.integer' => 'ID организации должен быть числом',
            'organization_ids.*.exists' => 'Организация не найдена',
            'date_from.date' => 'Некорректная дата начала периода',
            'date_to.date' => 'Некорректная дата окончания периода',
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала',
            'export_format.in' => 'Формат экспорта должен быть csv, excel или xlsx',
            'contractor_ids.array' => 'Список подрядчиков должен быть массивом',
            'contractor_ids.*.integer' => 'ID подрядчика должен быть числом',
            'contractor_ids.*.exists' => 'Подрядчик не найден',
            'project_id.exists' => 'Проект не найден',
            'per_page.min' => 'Минимальное количество элементов на странице: 1',
            'per_page.max' => 'Максимальное количество элементов на странице: 1000',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'include_archived' => $this->boolean('include_archived', false),
            'include_child_contracts' => $this->boolean('include_child_contracts', false),
            'per_page' => $this->integer('per_page', 50),
            'page' => $this->integer('page', 1),
            'sort_direction' => $this->input('sort_direction', 'desc'),
        ]);
    }
}

