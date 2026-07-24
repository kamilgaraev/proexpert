<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHoldingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'group_id' => 'required|integer|exists:organization_groups,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'max_child_organizations' => 'sometimes|integer|min:1|max:100',
            'settings' => 'sometimes|array',
            'permissions_config' => 'sometimes|array',
        ];
    }
}
