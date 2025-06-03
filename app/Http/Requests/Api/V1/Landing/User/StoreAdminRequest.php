<?php

namespace App\Http\Requests\Api\V1\Landing\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use App\Models\Role;

class StoreAdminRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        $organizationId = $user->current_organization_id;
        if (!$organizationId) {
            return false;
        }

        $isOwner = $user->isOwnerOfOrganization($organizationId);
        $isAdmin = $user->hasRole(Role::ROLE_ADMIN, $organizationId);

        return $isOwner || $isAdmin;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,NULL,id,deleted_at,NULL',
            'password' => 'required|string|min:8|confirmed',
        ];
    }
} 