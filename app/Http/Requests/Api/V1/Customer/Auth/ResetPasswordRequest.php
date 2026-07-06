<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Customer\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

use function trans_message;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->mixedCase()->numbers(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => trans_message('customer.auth.validation.reset_token_required'),
            'email.required' => trans_message('customer.auth.validation.email_required'),
            'email.email' => trans_message('customer.auth.validation.email_invalid'),
            'password.required' => trans_message('customer.auth.validation.password_required'),
            'password.min' => trans_message('customer.auth.validation.password_min'),
            'password.confirmed' => trans_message('customer.auth.validation.password_confirmation'),
            'password.password.mixed' => trans_message('customer.auth.validation.password_complexity'),
            'password.password.numbers' => trans_message('customer.auth.validation.password_complexity'),
        ];
    }
}
