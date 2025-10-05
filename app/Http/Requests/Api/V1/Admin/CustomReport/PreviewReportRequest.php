<?php

namespace App\Http\Requests\Api\V1\Admin\CustomReport;

use Illuminate\Foundation\Http\FormRequest;

class PreviewReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('view-reports');
    }

    public function rules(): array
    {
        return [
            'data_sources' => 'required|array',
            'data_sources.primary' => 'required|string',
            'data_sources.joins' => 'nullable|array',
            'query_config' => 'nullable|array',
            'columns_config' => 'required|array|min:1',
            'filters' => 'nullable|array',
            'aggregations_config' => 'nullable|array',
            'sorting_config' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'data_sources.required' => 'Необходимо указать источники данных',
            'data_sources.primary.required' => 'Необходимо указать основной источник данных',
            'columns_config.required' => 'Необходимо выбрать хотя бы одну колонку',
            'columns_config.min' => 'Необходимо выбрать хотя бы одну колонку',
        ];
    }
}
