<?php

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

class CreateEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => 'nullable|exists:contracts,id',
            'number' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:local,object,summary,contractual',
            'estimate_date' => 'required|date',
            'base_price_date' => 'nullable|date',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'overhead_rate' => 'nullable|numeric|min:0|max:100',
            'profit_rate' => 'nullable|numeric|min:0|max:100',
            'calculation_method' => 'nullable|in:base_index,resource,resource_index,analog',
            'metadata' => 'nullable|array',
            'template_id' => 'nullable|exists:estimate_templates,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название сметы обязательно',
            'type.required' => 'Тип сметы обязателен',
            'estimate_date.required' => 'Дата сметы обязательна',
        ];
    }
}

