<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachEstimateItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estimate_id' => 'required|integer|exists:estimates,id',
            'item_ids'    => 'required|array|min:1',
            'item_ids.*'  => 'integer|exists:estimate_items,id',
            'include_vat' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'estimate_id.required' => 'Укажите смету',
            'estimate_id.exists'   => 'Смета не найдена',
            'item_ids.required'    => 'Укажите хотя бы одну позицию сметы',
            'item_ids.min'         => 'Укажите хотя бы одну позицию сметы',
            'item_ids.*.exists'    => 'Одна или несколько позиций сметы не найдены',
        ];
    }
}
