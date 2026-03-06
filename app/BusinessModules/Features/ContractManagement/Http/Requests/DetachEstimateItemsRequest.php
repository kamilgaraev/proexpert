<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DetachEstimateItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_ids'   => 'required|array|min:1',
            'item_ids.*' => 'integer|exists:estimate_items,id',
        ];
    }

    public function messages(): array
    {
        return [
            'item_ids.required' => 'Укажите хотя бы одну позицию для отвязки',
            'item_ids.min'      => 'Укажите хотя бы одну позицию для отвязки',
        ];
    }
}
