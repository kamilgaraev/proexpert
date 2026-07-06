<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Brigades\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

use function trans_message;

class RegisterBrigadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'team_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'contact_person' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'contact_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'regions' => ['nullable', 'array'],
            'regions.*' => ['string', 'max:255'],
            'specializations' => ['nullable', 'array'],
            'specializations.*' => ['string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => trans_message('auth.validation.password_required'),
            'password.min' => trans_message('auth.validation.password_min'),
            'password.confirmed' => trans_message('auth.validation.password_confirmation'),
            'password.password.mixed' => trans_message('auth.validation.password_complexity'),
            'password.password.numbers' => trans_message('auth.validation.password_complexity'),
        ];
    }
}
