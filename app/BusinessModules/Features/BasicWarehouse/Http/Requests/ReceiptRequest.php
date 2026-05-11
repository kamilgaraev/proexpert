<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $material = $this->input('material');
        $metadata = $this->input('metadata');

        $this->merge([
            'material' => is_string($material) ? json_decode($material, true) : $material,
            'metadata' => is_string($metadata) ? json_decode($metadata, true) : $metadata,
        ]);
    }

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
                'nullable',
                Rule::exists('materials', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'material' => 'nullable|array',
            'material.name' => 'required_without:material_id|string|max:255',
            'material.code' => 'nullable|string|max:50',
            'material.measurement_unit_id' => [
                'required_without:material_id',
                Rule::exists('measurement_units', 'id')
                    ->whereNull('deleted_at')
                    ->where(static function ($query) use ($organizationId): void {
                        $query->where('organization_id', $organizationId)
                            ->orWhere('is_system', true);
                    }),
            ],
            'material.category' => 'nullable|string|max:100',
            'material.asset_type' => 'nullable|string|in:material,equipment,tool,furniture,consumable,structure',
            'material.default_price' => 'nullable|numeric|min:0',
            'material.description' => 'nullable|string',
            'quantity' => 'required|numeric|min:0.001',
            'price' => 'required|numeric|min:0',
            'project_id' => 'nullable|exists:projects,id',
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'photos' => 'nullable|array|max:4',
            'photos.*' => 'file|image|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
        ];
    }
}
