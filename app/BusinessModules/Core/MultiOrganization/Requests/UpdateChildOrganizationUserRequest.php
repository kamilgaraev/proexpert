<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChildOrganizationUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'role' => 'sometimes|string|in:admin,manager,employee',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
