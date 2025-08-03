<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractorReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Авторизация проверяется в middleware контроллера
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'contractor_ids' => ['nullable', 'array'],
            'contractor_ids.*' => ['integer', 'exists:contractors,id'],
            'contract_status' => ['nullable', 'string', Rule::in(['active', 'completed', 'cancelled', 'draft'])],
            'include_completed_works' => ['nullable', 'boolean'],
            'include_payments' => ['nullable', 'boolean'],
            'include_materials' => ['nullable', 'boolean'],
            'group_by' => ['nullable', 'string', Rule::in(['contractor', 'contract', 'work_type'])],
            'export_format' => ['nullable', 'string', Rule::in(['json', 'csv', 'excel'])],
            'sort_by' => ['nullable', 'string', Rule::in(['contractor_name', 'total_amount', 'completed_amount', 'payment_amount'])],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'project_id.required' => 'Необходимо указать проект для отчета.',
            'project_id.exists' => 'Указанный проект не найден.',
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала.',
            'contractor_ids.*.exists' => 'Один из указанных подрядчиков не найден.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Устанавливаем значения по умолчанию
        $this->merge([
            'include_completed_works' => $this->boolean('include_completed_works', true),
            'include_payments' => $this->boolean('include_payments', true),
            'include_materials' => $this->boolean('include_materials', false),
            'group_by' => $this->input('group_by', 'contractor'),
            'export_format' => $this->input('export_format', 'json'),
            'sort_by' => $this->input('sort_by', 'total_amount'),
            'sort_direction' => $this->input('sort_direction', 'desc'),
        ]);
    }
}