<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Customer\Auth;

use Illuminate\Foundation\Http\FormRequest;

use function trans_message;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => trans_message('customer.auth.validation.email_required'),
            'email.email' => trans_message('customer.auth.validation.email_invalid'),
            'password.required' => trans_message('customer.auth.validation.password_required'),
        ];
    }
}
