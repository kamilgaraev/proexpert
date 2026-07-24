<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChildOrganizationUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'sometimes|string|min:8',
            'auto_verify' => 'sometimes|boolean',
            'send_invitation' => 'sometimes|boolean',
            'role_data' => 'required|array',
            'role_data.slug' => 'sometimes|string|max:255',
            'role_data.template' => 'sometimes|string|in:administrator,project_manager,foreman,accountant,sales_manager,worker,observer',
            'role_data.name' => 'required_without:role_data.template|string|max:255',
            'role_data.description' => 'sometimes|string|max:1000',
            'role_data.permissions' => 'required_without:role_data.template|array',
            'role_data.permissions.*' => 'string',
            'role_data.color' => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'role_data.is_custom' => 'sometimes|boolean',
        ];
    }
}
