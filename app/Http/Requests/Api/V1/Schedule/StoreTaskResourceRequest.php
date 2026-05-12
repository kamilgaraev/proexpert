<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Schedule;

use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskResourceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('resource_type') === 'external') {
            $this->merge(['resource_type' => 'external_resource']);
        }
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        $organizationId = $this->getOrganizationId();

        if (!$organizationId) {
            return false;
        }

        return app(AuthorizationService::class)->can($user, 'schedule.edit', [
            'organization_id' => $organizationId,
            'context_type' => 'organization',
        ]);
    }

    public function rules(): array
    {
        $organizationId = $this->getOrganizationId();

        return [
            'resource_type' => ['required', Rule::in(['user', 'material', 'equipment', 'external_resource'])],
            'user_id' => [
                'required_if:resource_type,user',
                'nullable',
                'integer',
                'min:1',
                Rule::exists('organization_user', 'user_id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'material_id' => [
                'required_if:resource_type,material',
                'nullable',
                'integer',
                'min:1',
                Rule::exists('materials', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'equipment_name' => ['required_if:resource_type,equipment', 'nullable', 'string', 'max:255'],
            'external_resource_name' => ['required_if:resource_type,external_resource', 'nullable', 'string', 'max:255'],
            'allocation_percent' => ['required', 'numeric', 'min:1', 'max:100'],
            'assignment_start_date' => ['required', 'date'],
            'assignment_end_date' => ['required', 'date', 'after_or_equal:assignment_start_date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'allocated_units' => ['nullable', 'numeric', 'min:0'],
            'allocated_hours' => ['nullable', 'numeric', 'min:0'],
            'cost_per_hour' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'role' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function getOrganizationId(): ?int
    {
        $user = $this->user();
        $organizationId = $this->attributes->get('current_organization_id')
            ?? $user?->current_organization_id
            ?? $user?->organization_id;

        return $organizationId ? (int) $organizationId : null;
    }
}
