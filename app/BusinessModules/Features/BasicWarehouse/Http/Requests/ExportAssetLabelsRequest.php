<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportAssetLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'layout' => 'nullable|in:4,6,8',
            'asset_type' => 'nullable|in:material,equipment,tool,furniture,consumable,structure',
            'search' => 'nullable|string|max:255',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => [
                'integer',
                Rule::exists('materials', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
        ];
    }
}
