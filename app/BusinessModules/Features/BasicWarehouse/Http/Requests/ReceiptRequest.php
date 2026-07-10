<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $material = $this->decodeJsonInput($this->input('material'));
        $metadata = $this->decodeJsonInput($this->input('metadata'));

        if (is_array($material)) {
            $material['measurement_unit_id'] = $this->nullableInteger($material['measurement_unit_id'] ?? null);
            $material['default_price'] = $this->nullableFloat($material['default_price'] ?? null);
        }

        $this->merge([
            'warehouse_id' => $this->nullableInteger($this->input('warehouse_id')),
            'cell_id' => $this->nullableInteger($this->input('cell_id')),
            'material_id' => $this->nullableInteger($this->input('material_id')),
            'material' => $material,
            'quantity' => $this->nullableFloat($this->input('quantity')),
            'price' => $this->nullableFloat($this->input('price')),
            'project_id' => $this->nullableInteger($this->input('project_id')),
            'metadata' => $metadata,
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
            'cell_id' => 'nullable|integer',
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
            'project_id' => [
                'nullable',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'photos' => 'nullable|array|max:4',
            'photos.*' => 'file|image|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
        ];
    }

    private function decodeJsonInput(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function nullableInteger(mixed $value): mixed
    {
        $value = $this->normalizeNullableValue($value);

        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (int) $value : $value;
    }

    private function nullableFloat(mixed $value): mixed
    {
        $value = $this->normalizeNullableValue($value);

        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (float) $value : $value;
    }

    private function normalizeNullableValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $normalized = trim($value);

        return in_array(mb_strtolower($normalized), ['', 'null', 'undefined'], true)
            ? null
            : $normalized;
    }
}
