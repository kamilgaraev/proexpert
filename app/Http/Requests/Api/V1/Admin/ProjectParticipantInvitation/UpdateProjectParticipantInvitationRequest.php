<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ProjectParticipantInvitation;

use App\Enums\ProjectOrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectParticipantInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(array_map(
                static fn (ProjectOrganizationRole $role): string => $role->value,
                ProjectOrganizationRole::cases()
            ))],
        ];
    }
}
