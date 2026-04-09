<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ProjectParticipantInvitation;

use App\Enums\ProjectOrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectParticipantInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'role' => ['required', 'string', Rule::in(array_map(
                static fn (ProjectOrganizationRole $role): string => $role->value,
                ProjectOrganizationRole::cases()
            ))],
            'organization_name' => ['nullable', 'string', 'max:255', 'required_without:organization_id'],
            'inn' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:organization_id'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
