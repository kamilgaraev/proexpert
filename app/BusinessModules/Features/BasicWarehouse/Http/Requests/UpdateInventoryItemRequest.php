<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actual_quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'actual_quantity.required' => trans_message('basic_warehouse.inventory.actual_quantity_required'),
            'actual_quantity.numeric' => trans_message('basic_warehouse.inventory.actual_quantity_numeric'),
            'actual_quantity.min' => trans_message('basic_warehouse.inventory.actual_quantity_non_negative'),
        ];
    }
}
