<?php

namespace App\Http\Requests\Api\V1\Admin\EstimatePosition;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $organizationId = $this->user()->current_organization_id;

        return [
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('estimate_position_catalog_categories', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'metadata' => 'sometimes|nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'Указанная родительская категория не найдена',
        ];
    }
}

