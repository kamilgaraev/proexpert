<?php

namespace App\Http\Requests\Api\V1\Admin\ReportTemplate;

use App\Models\ReportTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'report_type' => ['required', 'string', Rule::in(ReportTemplate::validReportTypes())],
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
            'columns_config.required' => trans_message('report_templates.validation.columns_config.required'),
            'columns_config.array' => trans_message('report_templates.validation.columns_config.array'),
            'columns_config.min' => trans_message('report_templates.validation.columns_config.min'),
            'columns_config.*.header.required' => trans_message('report_templates.validation.column.header_required'),
            'columns_config.*.data_key.required' => trans_message('report_templates.validation.column.data_key_required'),
            'columns_config.*.order.required' => trans_message('report_templates.validation.column.order_required'),
            'columns_config.*.order.integer' => trans_message('report_templates.validation.column.order_integer'),
        ];
    }
}
