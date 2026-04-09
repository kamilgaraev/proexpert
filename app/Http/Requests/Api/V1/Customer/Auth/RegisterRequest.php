<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Customer\Auth;

use Illuminate\Foundation\Http\FormRequest;

use function trans_message;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:100'],
            'organization_name' => ['required', 'string', 'max:255', 'min:2'],
            'organization_legal_name' => ['nullable', 'string', 'max:255', 'min:2'],
            'organization_tax_number' => ['nullable', 'string', 'regex:/^(\d{10}|\d{12})$/'],
            'organization_registration_number' => ['nullable', 'string', 'regex:/^(\d{13}|\d{15})$/'],
            'organization_phone' => ['nullable', 'string', 'max:20'],
            'organization_email' => ['nullable', 'string', 'email', 'max:255'],
            'organization_address' => ['nullable', 'string', 'max:500'],
            'organization_city' => ['nullable', 'string', 'max:100'],
            'organization_postal_code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'organization_country' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => trans_message('customer.auth.validation.name_required'),
            'email.required' => trans_message('customer.auth.validation.email_required'),
            'email.email' => trans_message('customer.auth.validation.email_invalid'),
            'email.unique' => trans_message('customer.auth.validation.email_taken'),
            'password.required' => trans_message('customer.auth.validation.password_required'),
            'password.min' => trans_message('customer.auth.validation.password_min'),
            'password.confirmed' => trans_message('customer.auth.validation.password_confirmation'),
            'password.regex' => trans_message('customer.auth.validation.password_complexity'),
            'organization_name.required' => trans_message('customer.auth.validation.organization_name_required'),
            'organization_name.min' => trans_message('customer.auth.validation.organization_name_min'),
            'organization_tax_number.regex' => trans_message('customer.auth.validation.organization_tax_number'),
            'organization_registration_number.regex' => trans_message('customer.auth.validation.organization_registration_number'),
            'organization_email.email' => trans_message('customer.auth.validation.organization_email_invalid'),
            'organization_postal_code.regex' => trans_message('customer.auth.validation.organization_postal_code'),
        ];
    }
}
