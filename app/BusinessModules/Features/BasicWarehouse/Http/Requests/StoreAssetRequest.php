<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
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

        return [
            'name'                => 'required|string|max:255',
            'code'                => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('materials', 'code')->where('organization_id', $organizationId),
            ],
            'measurement_unit_id' => 'required|exists:measurement_units,id',
            'asset_type'          => ['required', 'string', Rule::in(array_keys(Asset::getAssetTypes()))],
            'asset_category'      => 'nullable|string|max:100',
            'asset_subcategory'   => 'nullable|string|max:100',
            'default_price'       => 'nullable|numeric|min:0',
            'description'         => 'nullable|string|max:1000',
            'category'            => 'nullable|string|max:100',
            'asset_attributes'    => 'nullable|array',
        ];
    }
}
