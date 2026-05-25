<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use function trans_message;

class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'from_warehouse_id' => [
                'required',
                Rule::exists('organization_warehouses', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'to_warehouse_id' => [
                'required',
                'different:from_warehouse_id',
                Rule::exists('organization_warehouses', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'material_id' => [
                'required',
                Rule::exists('materials', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'quantity' => 'required|numeric|min:0.001',
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'to_warehouse_id.different' => trans_message('warehouse_basic.validation.transfer_same_warehouse'),
        ];
    }
}
