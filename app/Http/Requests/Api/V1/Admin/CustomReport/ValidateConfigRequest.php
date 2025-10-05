<?php

namespace App\Http\Requests\Api\V1\Admin\CustomReport;

use Illuminate\Foundation\Http\FormRequest;

class ValidateConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('view-reports');
    }

    public function rules(): array
    {
        return [
            'data_sources' => 'nullable|array',
            'data_sources.primary' => 'nullable|string',
            'data_sources.joins' => 'nullable|array',
            'query_config' => 'nullable|array',
            'columns_config' => 'nullable|array',
            'filters_config' => 'nullable|array',
            'aggregations_config' => 'nullable|array',
            'sorting_config' => 'nullable|array',
            'visualization_config' => 'nullable|array',
        ];
    }
}
