<?php

namespace App\Http\Requests\Api\V1\Admin\UserManagement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreForemanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: Auth::user()->can('create_foreman')
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
 