<?php

namespace App\Http\Requests\Api\V1\Admin\ReportTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\ReportTemplate; // Для использования констант типов отчетов, если они будут

class StoreReportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Предполагаем, что доступ к контроллеру уже проверен middleware (например, can:manage-report-templates)
        return true;
    }

    public function rules(): array
    {
        // TODO: Вынести report_type в enum или константы модели ReportTemplate
        $validReportTypes = [
            'material_usage',
            'work_completion',
            'foreman_activity',
            'project_status_summary',
            'contractor_summary',
            'contractor_detail',
        ]; 

        return [
            'name' => ['required', 'string', 'max:255'],
            'report_type' => ['required', 'string', Rule::in($validReportTypes)],
            'columns_config' => ['required', 'array', 'min:1'],
            'columns_config.*.header' => ['required', 'string', 'max:255'],
            'columns_config.*.data_key' => ['required', 'string', 'max:255'],
            'columns_config.*.order' => ['required', 'integer', 'min:1'],
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
            'columns_config.*.header.required' => 'Заголовок колонки обязателен.',
            'columns_config.*.data_key.required' => 'Ключ данных для колонки обязателен.',
            'columns_config.*.order.required' => 'Порядок колонки обязателен.',
            'columns_config.*.order.integer' => 'Порядок колонки должен быть числом.',
        ];
    }
} 