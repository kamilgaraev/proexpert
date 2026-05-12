<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferToContractorRequest extends FormRequest
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
            'contractor_id' => [
                'required',
                Rule::exists('contractors', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'material_id' => [
                'required',
                Rule::exists('materials', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'quantity' => 'required|numeric|min:0.001',
            'project_id' => [
                'nullable',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
        ];
    }
}
