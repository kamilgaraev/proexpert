<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WriteOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'warehouse_id' => [
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
            'quantity' => 'required|numeric|min:0.001',
            'project_id' => [
                'nullable',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'document_number' => 'nullable|string|max:100',
            'reason' => 'required|string|max:255',
            'metadata' => 'nullable|array',
        ];
    }
}
