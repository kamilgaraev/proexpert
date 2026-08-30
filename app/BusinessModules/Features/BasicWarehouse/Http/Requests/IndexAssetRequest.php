<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_type' => ['sometimes', 'string', Rule::in(array_keys(Asset::getAssetTypes()))],
            'asset_category' => ['sometimes', 'string', 'max:100'],
            'warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'all'])],
            'sort_by' => ['sometimes', 'string', Rule::in(['name', 'code', 'created_at'])],
            'sort_order' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
