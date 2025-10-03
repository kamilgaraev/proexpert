<?php

namespace App\Http\Requests\Api\V1\Admin\CustomReport;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteCustomReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('view-reports');
    }

    public function rules(): array
    {
        return [
            'filters' => 'nullable|array',
            'export' => 'nullable|string|in:csv,excel,pdf',
            'limit' => 'nullable|integer|min:1|max:10000',
        ];
    }
}

