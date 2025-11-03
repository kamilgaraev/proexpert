<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('budget-estimates.edit');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'estimate_section_id' => 'nullable|integer|exists:estimate_sections,id',
            'position_number' => 'nullable|string|max:50',
            'name' => 'sometimes|string|max:500',
            'description' => 'nullable|string|max:5000',
            'work_type_id' => 'nullable|integer|exists:work_types,id',
            'measurement_unit_id' => 'sometimes|integer|exists:measurement_units,id',
            'quantity' => 'sometimes|numeric|min:0|max:999999999',
            'unit_price' => 'sometimes|numeric|min:0|max:999999999',
            'justification' => 'nullable|string|max:2000',
            'is_manual' => 'boolean',
            'metadata' => 'nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'quantity.min' => 'Количество не может быть отрицательным',
            'unit_price.min' => 'Цена не может быть отрицательной',
            'measurement_unit_id.exists' => 'Указанная единица измерения не существует',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('quantity')) {
            $this->merge([
                'quantity' => $this->convertToNumber($this->quantity),
            ]);
        }

        if ($this->has('unit_price')) {
            $this->merge([
                'unit_price' => $this->convertToNumber($this->unit_price),
            ]);
        }
    }

    /**
     * Преобразовать строку в число
     */
    private function convertToNumber($value)
    {
        if (is_numeric($value)) {
            return $value;
        }

        $value = str_replace([' ', ','], ['', '.'], $value);

        return is_numeric($value) ? (float) $value : $value;
    }
}

