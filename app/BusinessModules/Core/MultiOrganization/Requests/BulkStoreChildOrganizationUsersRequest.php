<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreChildOrganizationUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'users' => 'required|array|min:1|max:20',
            'users.*.name' => 'required|string|max:255',
            'users.*.email' => 'required|email|max:255',
            'users.*.password' => 'sometimes|string|min:8',
            'users.*.auto_verify' => 'sometimes|boolean',
            'users.*.send_invitation' => 'sometimes|boolean',
            'users.*.role_data' => 'required|array',
            'users.*.role_data.slug' => 'sometimes|string|max:255',
            'users.*.role_data.template' => 'sometimes|string|in:administrator,project_manager,foreman,accountant,sales_manager,worker,observer',
            'users.*.role_data.name' => 'required_without:users.*.role_data.template|string|max:255',
            'users.*.role_data.permissions' => 'required_without:users.*.role_data.template|array',
            'users.*.role_data.permissions.*' => 'string',
            'users.*.role_data.is_custom' => 'sometimes|boolean',
        ];
    }
}
