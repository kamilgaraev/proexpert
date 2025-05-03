<?php

namespace App\Http\Requests\Api\V1\Admin\Material;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;

class StoreMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $organizationId = $this->attributes->get('organization_id');
        return $user && $organizationId && $user->isOrganizationAdmin($organizationId);
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('organization_id');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('materials', 'name')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at');
                    }),
            ],
            'measurement_unit_id' => 'required|integer|exists:measurement_units,id', // TODO: Проверить доступность ед. изм. для организации?
            'category' => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean',
        ];
    }
} 