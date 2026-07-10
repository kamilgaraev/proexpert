<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
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
            'from_cell_id' => 'nullable|integer',
            'to_cell_id' => 'nullable|integer',
            'quantity' => 'required|numeric|min:0.001',
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fromWarehouseId = $this->integer('from_warehouse_id');
            $toWarehouseId = $this->integer('to_warehouse_id');
            $fromCellId = $this->input('from_cell_id');
            $toCellId = $this->input('to_cell_id');

            if ($fromWarehouseId === $toWarehouseId && ($fromCellId === null || $toCellId === null)) {
                $validator->errors()->add('to_warehouse_id', trans_message('warehouse_basic.validation.transfer_same_warehouse'));
            }

            if ($fromCellId !== null && $toCellId !== null && (int) $fromCellId === (int) $toCellId) {
                $validator->errors()->add('to_cell_id', trans_message('warehouse_basic.validation.transfer_same_cell'));
            }
        });
    }

    public function messages(): array
    {
        return [];
    }
}
