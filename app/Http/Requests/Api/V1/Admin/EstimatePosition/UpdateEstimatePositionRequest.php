<?php

namespace App\Http\Requests\Api\V1\Admin\EstimatePosition;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\EstimatePositionItemType;

class UpdateEstimatePositionRequest extends FormRequest
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
        $positionId = $this->route('id');

        return [
            'category_id' => 'sometimes|nullable|integer|exists:estimate_position_catalog_categories,id',
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes',
                'string',
                'max:255',
                "unique:estimate_position_catalog,code,{$positionId},id,organization_id,{$organizationId}",
            ],
            'description' => 'sometimes|nullable|string',
            'item_type' => 'sometimes|in:' . implode(',', EstimatePositionItemType::values()),
            'measurement_unit_id' => 'sometimes|integer|exists:measurement_units,id',
            'work_type_id' => 'sometimes|nullable|integer|exists:work_types,id',
            'unit_price' => 'sometimes|numeric|min:0',
            'direct_costs' => 'sometimes|nullable|numeric|min:0',
            'overhead_percent' => 'sometimes|nullable|numeric|min:0|max:100',
            'profit_percent' => 'sometimes|nullable|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
            'metadata' => 'sometimes|nullable|array',
            'price_change_reason' => 'sometimes|nullable|string|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Позиция с таким кодом уже существует',
            'item_type.in' => 'Недопустимый тип позиции',
            'measurement_unit_id.exists' => 'Указанная единица измерения не найдена',
            'unit_price.numeric' => 'Цена должна быть числом',
            'unit_price.min' => 'Цена не может быть отрицательной',
        ];
    }
}

