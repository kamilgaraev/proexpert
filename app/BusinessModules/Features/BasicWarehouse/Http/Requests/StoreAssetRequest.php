<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
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

        if (!$this->filled('warehouse_id')) {
            $codeRules[] = Rule::unique('materials', 'code')->where('organization_id', $organizationId);
        }

        return [
            'name'                => 'required|string|max:255',
            'code'                => $codeRules,
            'measurement_unit_id' => [
                'required',
                Rule::exists('measurement_units', 'id')
                    ->whereNull('deleted_at')
                    ->where(static function ($query) use ($organizationId): void {
                        $query->where('organization_id', $organizationId)
                            ->orWhere('is_system', true);
                    }),
            ],
            'asset_type'          => ['required', 'string', Rule::in(array_keys(Asset::getAssetTypes()))],
            'asset_category'      => 'nullable|string|max:100',
            'asset_subcategory'   => 'nullable|string|max:100',
            'default_price'       => 'nullable|numeric|min:0',
            'description'         => 'nullable|string|max:1000',
            'category'            => 'nullable|string|max:100',
            'asset_attributes'    => 'nullable|array',
            'warehouse_id'         => [
                'nullable',
                'integer',
                Rule::exists(OrganizationWarehouse::class, 'id')
                    ->where(static function ($query) use ($organizationId): void {
                        $query->where('organization_id', $organizationId)
                            ->where('is_active', true);
                    }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => trans_message('basic_warehouse.asset.code_exists', [], 'ru'),
        ];
    }
}
