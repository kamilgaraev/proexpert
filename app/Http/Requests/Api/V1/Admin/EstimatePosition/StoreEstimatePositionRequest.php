<?php

namespace App\Http\Requests\Api\V1\Admin\EstimatePosition;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\EstimatePositionItemType;

class StoreEstimatePositionRequest extends FormRequest
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
            'category_id' => 'nullable|integer|exists:estimate_position_catalog_categories,id',
            'name' => 'required|string|max:255',
            'code' => [
                'required',
                'string',
                'max:255',
                "unique:estimate_position_catalog,code,NULL,id,organization_id,{$organizationId}",
            ],
            'description' => 'nullable|string',
            'item_type' => 'required|in:' . implode(',', EstimatePositionItemType::values()),
            'measurement_unit_id' => 'required|integer|exists:measurement_units,id',
            'work_type_id' => 'nullable|integer|exists:work_types,id',
            'unit_price' => 'required|numeric|min:0',
            'direct_costs' => 'nullable|numeric|min:0',
            'overhead_percent' => 'nullable|numeric|min:0|max:100',
            'profit_percent' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Название позиции обязательно',
            'code.required' => 'Код позиции обязателен',
            'code.unique' => 'Позиция с таким кодом уже существует',
            'item_type.required' => 'Тип позиции обязателен',
            'item_type.in' => 'Недопустимый тип позиции',
            'measurement_unit_id.required' => 'Единица измерения обязательна',
            'measurement_unit_id.exists' => 'Указанная единица измерения не найдена',
            'unit_price.required' => 'Цена за единицу обязательна',
            'unit_price.numeric' => 'Цена должна быть числом',
            'unit_price.min' => 'Цена не может быть отрицательной',
        ];
    }
}

