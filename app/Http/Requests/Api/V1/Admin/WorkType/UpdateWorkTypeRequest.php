<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\WorkType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('admin.catalogs.manage');
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id');
        $workTypeId = (int) $this->route('work_type');

        if (!$organizationId || $workTypeId <= 0) {
            return [];
        }

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('work_types', 'name')
                    ->where(fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->whereNull('deleted_at'))
                    ->ignore($workTypeId),
            ],
            'measurement_unit_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('measurement_units', 'id')
                    ->where(fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->where(fn ($nested) => $nested
                            ->where('organization_id', $organizationId)
                            ->orWhere('is_system', true))),
            ],
            'code' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'default_price' => ['nullable', 'numeric', 'min:0'],
            'additional_properties' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'organization_id' => ['sometimes', 'integer'],
        ];
    }
}
