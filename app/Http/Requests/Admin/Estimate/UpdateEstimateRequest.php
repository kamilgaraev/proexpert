<?php

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => 'sometimes|nullable|exists:projects,id',
            'contract_id' => 'sometimes|nullable|exists:contracts,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|in:local,object,summary,contractual',
            'estimate_date' => 'sometimes|date',
            'base_price_date' => 'nullable|date',
            'vat_rate' => 'sometimes|numeric|min:0|max:100',
            'overhead_rate' => 'sometimes|numeric|min:0|max:100',
            'profit_rate' => 'sometimes|numeric|min:0|max:100',
            'calculation_method' => 'sometimes|in:base_index,resource,resource_index,analog',
            'status' => 'sometimes|in:draft,in_review,approved,cancelled',
            'metadata' => 'nullable|array',
        ];
    }
}

