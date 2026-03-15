<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportAssetLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'layout' => 'nullable|in:4,6,8',
            'asset_type' => 'nullable|in:material,equipment,tool,furniture,consumable,structure',
            'search' => 'nullable|string|max:255',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => 'integer|exists:materials,id',
        ];
    }
}
