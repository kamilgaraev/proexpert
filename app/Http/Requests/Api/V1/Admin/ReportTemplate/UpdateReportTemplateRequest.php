<?php

namespace App\Http\Requests\Api\V1\Admin\ReportTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\ReportTemplate; // Для использования констант типов отчетов, если они будут

class UpdateReportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // TODO: Вынести report_type в enum или константы модели ReportTemplate
        $validReportTypes = ['material_usage', 'work_completion', 'foreman_activity', 'project_status_summary'];

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'report_type' => ['sometimes', 'required', 'string', Rule::in($validReportTypes)],
            'columns_config' => ['sometimes', 'required', 'array', 'min:1'],
            'columns_config.*.header' => ['required_with:columns_config', 'string', 'max:255'],
            'columns_config.*.data_key' => ['required_with:columns_config', 'string', 'max:255'],
            'columns_config.*.order' => ['required_with:columns_config', 'integer', 'min:1'],
            'columns_config.*.format_options' => ['nullable', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'columns_config.required' => 'Необходимо определить конфигурацию колонок.',
            'columns_config.array' => 'Конфигурация колонок должна быть массивом.',
            'columns_config.min' => 'Конфигурация колонок должна содержать хотя бы одну колонку.',
            'columns_config.*.header.required_with' => 'Заголовок колонки обязателен, если передается конфигурация колонок.',
            'columns_config.*.data_key.required_with' => 'Ключ данных для колонки обязателен, если передается конфигурация колонок.',
            'columns_config.*.order.required_with' => 'Порядок колонки обязателен, если передается конфигурация колонок.',
            'columns_config.*.order.integer' => 'Порядок колонки должен быть числом.',
        ];
    }
} 