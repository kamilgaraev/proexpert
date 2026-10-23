<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\ProjectAccessibleRule;

class ReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $material = $this->decodeJsonInput($this->input('material'));
        $metadata = $this->decodeJsonInput($this->input('metadata'));
        $vatMode = $this->normalizeNullableValue($this->input('vat_mode'));

        if (is_array($material)) {
            $material['measurement_unit_id'] = $this->nullableInteger($material['measurement_unit_id'] ?? null);
            $material['default_price'] = $this->nullableFloat($material['default_price'] ?? null);
        }

        $this->merge([
            'warehouse_id' => $this->nullableInteger($this->input('warehouse_id')),
            'cell_id' => $this->nullableInteger($this->input('cell_id')),
            'material_id' => $this->nullableInteger($this->input('material_id')),
            'material' => $material,
            'quantity' => $vatMode === null ? $this->nullableFloat($this->input('quantity')) : $this->normalizeNullableValue($this->input('quantity')),
            'price' => $vatMode === null ? $this->nullableFloat($this->input('price')) : $this->normalizeNullableValue($this->input('price')),
            'vat_mode' => $vatMode,
            'vat_rate' => $this->normalizeNullableValue($this->input('vat_rate')),
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
        $hasVatContext = $this->input('vat_mode') !== null;
        $taxable = in_array($this->input('vat_mode'), ['included', 'on_top'], true);

        return [
            'idempotency_key' => 'required|uuid',
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
            'quantity' => $hasVatContext ? ['required', 'numeric', 'min:0.001', 'max:999999999999.999', 'decimal:0,3', 'regex:/\A\d+(?:\.\d+)?\z/'] : 'required|numeric|min:0.001',
            'price' => $hasVatContext ? ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2', 'regex:/\A\d+(?:\.\d+)?\z/'] : 'required|numeric|min:0',
            'vat_mode' => ['nullable', Rule::in(['none', 'included', 'on_top'])],
            'vat_rate' => [Rule::requiredIf($taxable), Rule::prohibitedIf(! $taxable), 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2', 'regex:/\A\d+(?:\.\d+)?\z/'],
            'project_id' => [
                'nullable',
                'integer',
                new ProjectAccessibleRule($organizationId),
            ],
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'metadata.description' => 'nullable|string|max:1000',
            'metadata.supplier_name' => 'nullable|string|max:255',
            'metadata.invoice_number' => 'nullable|string|max:100',
            'metadata.supplier_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999', 'decimal:0,3', 'regex:/\A\d+(?:\.\d+)?\z/'],
            'photos' => 'nullable|array|max:4',
            'photos.*' => 'file|image|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
            'passport' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:20480',
        ];
    }

    public function attributes(): array
    {
        return [
            'vat_mode' => trans_message('warehouse_basic.receipt_attributes.vat_mode'),
            'vat_rate' => trans_message('warehouse_basic.receipt_attributes.vat_rate'),
            'quantity' => trans_message('warehouse_basic.receipt_attributes.quantity'),
            'price' => trans_message('warehouse_basic.receipt_attributes.price'),
            'metadata.supplier_name' => trans_message('warehouse_basic.receipt_attributes.supplier_name'),
            'metadata.invoice_number' => trans_message('warehouse_basic.receipt_attributes.invoice_number'),
            'metadata.supplier_quantity' => trans_message('warehouse_basic.receipt_attributes.supplier_quantity'),
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.decimal' => trans_message('warehouse_basic.receipt_validation.quantity_decimal'),
            'quantity.regex' => trans_message('warehouse_basic.receipt_validation.quantity_decimal'),
            'price.decimal' => trans_message('warehouse_basic.receipt_validation.price_decimal'),
            'price.regex' => trans_message('warehouse_basic.receipt_validation.price_decimal'),
            'vat_rate.decimal' => trans_message('warehouse_basic.receipt_validation.vat_rate_decimal'),
            'vat_rate.regex' => trans_message('warehouse_basic.receipt_validation.vat_rate_decimal'),
            'vat_rate.prohibited' => trans_message('warehouse_basic.receipt_validation.vat_rate_prohibited'),
            'metadata.supplier_quantity.decimal' => trans_message('warehouse_basic.receipt_validation.supplier_quantity_decimal'),
            'metadata.supplier_quantity.regex' => trans_message('warehouse_basic.receipt_validation.supplier_quantity_decimal'),
        ];
    }

    private function decodeJsonInput(mixed $value): mixed
    {
        if (! is_string($value)) {
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
        if (! is_string($value)) {
            return $value;
        }

        $normalized = trim($value);

        return in_array(mb_strtolower($normalized), ['', 'null', 'undefined'], true)
            ? null
            : $normalized;
    }
}
