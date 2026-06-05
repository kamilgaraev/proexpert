<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\WorkType;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();
        $organizationId = (int) $this->attributes->get('current_organization_id');

        if (!$user || $organizationId <= 0) {
            return false;
        }

        return app(AuthorizationService::class)->can($user, 'work_types.create', [
            'organization_id' => $organizationId,
        ]);
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id');

        if (!$organizationId) {
            return [];
        }

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('work_types', 'name')
                    ->where(fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->whereNull('deleted_at')),
            ],
            'measurement_unit_id' => [
                'required',
                'integer',
                Rule::exists('measurement_units', 'id')
                    ->where(fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->where(fn ($nested) => $nested
                            ->where('organization_id', $organizationId)
                            ->orWhere('is_system', true))),
            ],
            'category' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'code' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'default_price' => ['nullable', 'numeric', 'min:0'],
            'additional_properties' => ['nullable', 'array'],
            'organization_id' => ['sometimes', 'integer'],
        ];
    }
}
