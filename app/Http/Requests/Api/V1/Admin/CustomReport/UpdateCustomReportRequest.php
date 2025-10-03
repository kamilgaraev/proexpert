<?php

namespace App\Http\Requests\Api\V1\Admin\CustomReport;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('view-reports');
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'report_category' => 'sometimes|required|string|in:core,finances,materials,works,staff',
            'data_sources' => 'sometimes|required|array',
            'data_sources.primary' => 'sometimes|required|string',
            'query_config' => 'nullable|array',
            'columns_config' => 'sometimes|required|array|min:1',
            'filters_config' => 'nullable|array',
            'aggregations_config' => 'nullable|array',
            'sorting_config' => 'nullable|array',
            'visualization_config' => 'nullable|array',
            'is_shared' => 'nullable|boolean',
        ];
    }
}

