<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()->current_organization_id;
        $assetId = $this->route('id');

        return [
            'name'                => 'sometimes|string|max:255',
            'code'                => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('materials', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($assetId),
            ],
            'measurement_unit_id' => 'sometimes|exists:measurement_units,id',
            'asset_type'          => ['sometimes', 'string', Rule::in(array_keys(Asset::getAssetTypes()))],
            'asset_category'      => 'sometimes|nullable|string|max:100',
            'asset_subcategory'   => 'sometimes|nullable|string|max:100',
            'default_price'       => 'sometimes|nullable|numeric|min:0',
            'description'         => 'sometimes|nullable|string|max:1000',
            'category'            => 'sometimes|nullable|string|max:100',
            'asset_attributes'    => 'sometimes|nullable|array',
            'is_active'           => 'sometimes|boolean',
        ];
    }
}
