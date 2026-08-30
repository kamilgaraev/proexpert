<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use App\BusinessModules\Core\AssetManagement\Enums\AssetAccountingMode;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('accounting_mode')) {
            $this->merge([
                'accounting_mode' => $this->input('asset_type') === Asset::TYPE_EQUIPMENT
                    ? AssetAccountingMode::Serialized->value
                    : AssetAccountingMode::Quantitative->value,
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()->current_organization_id;
        $codeRules = [
            'nullable',
            'string',
            'max:50',
        ];

        if (! $this->filled('warehouse_id')) {
            $codeRules[] = Rule::unique('materials', 'code')->where('organization_id', $organizationId);
        }

        return [
            'name' => 'required|string|max:255',
            'code' => $codeRules,
            'measurement_unit_id' => [
                'required',
                Rule::exists('measurement_units', 'id')
                    ->whereNull('deleted_at')
                    ->where(static function ($query) use ($organizationId): void {
                        $query->where('organization_id', $organizationId)
                            ->orWhere('is_system', true);
                    }),
            ],
            'asset_type' => ['required', 'string', Rule::in(array_keys(Asset::getAssetTypes()))],
            'accounting_mode' => ['required', Rule::enum(AssetAccountingMode::class)],
            'asset_category' => 'nullable|string|max:100',
            'asset_subcategory' => 'nullable|string|max:100',
            'default_price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:100',
            'asset_attributes' => 'nullable|array',
            'warehouse_id' => [
                Rule::requiredIf(
                    fn (): bool => $this->has('instances')
                        && $this->input('accounting_mode') === AssetAccountingMode::Serialized->value,
                ),
                'nullable',
                'integer',
                Rule::exists(OrganizationWarehouse::class, 'id')
                    ->where(static function ($query) use ($organizationId): void {
                        $query->where('organization_id', $organizationId)
                            ->where('is_active', true);
                    }),
            ],
            'instances' => [
                'sometimes',
                'array',
                'min:1',
                'max:50',
                Rule::prohibitedIf(
                    fn (): bool => $this->input('accounting_mode') !== AssetAccountingMode::Serialized->value,
                ),
            ],
            'instances.*.inventory_number' => ['required', 'string', 'max:120', 'distinct:strict'],
            'instances.*.serial_number' => ['nullable', 'string', 'max:160', 'distinct:strict'],
            'instances.*.qr_code' => ['nullable', 'string', 'max:160', 'distinct:strict'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => trans_message('basic_warehouse.asset.code_exists', [], 'ru'),
            'instances.prohibited' => trans_message('basic_warehouse.asset.instances_require_serialized', [], 'ru'),
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => trans_message('basic_warehouse.asset.fields.name', [], 'ru'),
            'code' => trans_message('basic_warehouse.asset.fields.code', [], 'ru'),
            'measurement_unit_id' => trans_message('basic_warehouse.asset.fields.measurement_unit', [], 'ru'),
            'asset_type' => trans_message('basic_warehouse.asset.fields.asset_type', [], 'ru'),
            'accounting_mode' => trans_message('basic_warehouse.asset.fields.accounting_mode', [], 'ru'),
            'asset_category' => trans_message('basic_warehouse.asset.fields.asset_category', [], 'ru'),
            'asset_subcategory' => trans_message('basic_warehouse.asset.fields.asset_subcategory', [], 'ru'),
            'default_price' => trans_message('basic_warehouse.asset.fields.default_price', [], 'ru'),
            'description' => trans_message('basic_warehouse.asset.fields.description', [], 'ru'),
            'warehouse_id' => trans_message('basic_warehouse.asset.fields.warehouse', [], 'ru'),
            'instances' => trans_message('basic_warehouse.asset.fields.instances', [], 'ru'),
            'instances.*.inventory_number' => trans_message('basic_warehouse.asset.fields.inventory_number', [], 'ru'),
            'instances.*.serial_number' => trans_message('basic_warehouse.asset.fields.serial_number', [], 'ru'),
            'instances.*.qr_code' => trans_message('basic_warehouse.asset.fields.qr_code', [], 'ru'),
        ];
    }
}
