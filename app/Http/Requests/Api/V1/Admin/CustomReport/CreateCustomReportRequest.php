<?php

namespace App\Http\Requests\Api\V1\Admin\CustomReport;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('view-reports');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'report_category' => 'required|string|in:core,finances,materials,works,staff',
            'data_sources' => 'required|array',
            'data_sources.primary' => 'required|string',
            'data_sources.joins' => 'nullable|array',
            'query_config' => 'nullable|array',
            'query_config.where' => 'nullable|array',
            'query_config.where_logic' => 'nullable|string|in:and,or',
            'columns_config' => 'required|array|min:1',
            'columns_config.*.field' => 'required|string',
            'columns_config.*.label' => 'required|string',
            'columns_config.*.order' => 'required|integer',
            'columns_config.*.format' => 'nullable|string',
            'columns_config.*.aggregation' => 'nullable|string',
            'filters_config' => 'nullable|array',
            'aggregations_config' => 'nullable|array',
            'sorting_config' => 'nullable|array',
            'visualization_config' => 'nullable|array',
            'is_shared' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название отчета обязательно',
            'report_category.required' => 'Категория отчета обязательна',
            'data_sources.required' => 'Необходимо указать источники данных',
            'columns_config.required' => 'Необходимо выбрать хотя бы одну колонку',
        ];
    }
}

