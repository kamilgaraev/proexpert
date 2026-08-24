<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function trans_message;

class RegisterRequest extends FormRequest
{
    /**
     * Определяет, авторизован ли пользователь для выполнения запроса.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);

        if ($this->has('email')) {
            $this->merge([
                'email' => Str::lower(trim((string) $this->input('email'))),
            ]);
        }
    }

    /**
     * Правила валидации для запроса.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Данные пользователя
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->mixedCase()->numbers(),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^(\+7|8)[- ]?\(?[0-9]{3}\)?[- ]?[0-9]{3}[- ]?[0-9]{2}[- ]?[0-9]{2}$/', // Российский формат телефона
            ],
            'position' => 'nullable|string|max:100',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',

            // Данные организации
            'organization_name' => 'required|string|max:255|min:2',
            'organization_legal_name' => 'nullable|string|max:255|min:2',
            'organization_tax_number' => [
                'nullable',
                'string',
                'regex:/^(\d{10}|\d{12})$/',
            ],
            'organization_registration_number' => [
                'nullable',
                'string',
                'regex:/^(\d{13}|\d{15})$/',
            ],
            'organization_phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^(\+7|8)[- ]?\(?[0-9]{3}\)?[- ]?[0-9]{3}[- ]?[0-9]{2}[- ]?[0-9]{2}$/', // Российский формат телефона
            ],
            'organization_email' => 'nullable|string|email|max:255',
            'organization_address' => 'nullable|string|max:500|min:10',
            'organization_city' => 'nullable|string|max:100|min:2|regex:/^[а-яёА-ЯЁa-zA-Z\s\-\.]+$/u',
            'organization_postal_code' => [
                'nullable',
                'string',
                'regex:/^\d{6}$/',
            ],
            'organization_country' => 'nullable|string|max:100|min:2',
            'terms_accepted' => ['required', 'accepted'],
            'privacy_accepted' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }

    /**
     * Кастомные сообщения об ошибках валидации.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => trans_message('auth.validation.name_required'),
            'email.required' => trans_message('auth.validation.email_required'),
            'email.email' => trans_message('auth.validation.email_invalid'),
            'email.unique' => trans_message('auth.validation.email_exists'),
            'password.required' => trans_message('auth.validation.password_required'),
            'password.min' => trans_message('auth.validation.password_min'),
            'password.confirmed' => trans_message('auth.validation.password_confirmation'),
            'password.password.mixed' => trans_message('auth.validation.password_complexity'),
            'password.password.numbers' => trans_message('auth.validation.password_complexity'),
            'phone.regex' => trans_message('auth.validation.phone_invalid'),

            'organization_name.required' => trans_message('auth.validation.organization_name_required'),
            'organization_name.min' => trans_message('auth.validation.organization_name_min'),
            'organization_legal_name.min' => trans_message('auth.validation.organization_legal_name_min'),

            'organization_tax_number.regex' => trans_message('auth.validation.organization_tax_number_invalid'),
            'organization_registration_number.regex' => trans_message('auth.validation.organization_registration_number_invalid'),

            'organization_phone.regex' => trans_message('auth.validation.phone_invalid'),
            'organization_email.email' => trans_message('auth.validation.organization_email_invalid'),

            'organization_address.min' => trans_message('auth.validation.organization_address_min'),
            'organization_address.max' => trans_message('auth.validation.organization_address_max'),

            'organization_city.min' => trans_message('auth.validation.organization_city_min'),
            'organization_city.regex' => trans_message('auth.validation.organization_city_invalid'),

            'organization_postal_code.regex' => trans_message('auth.validation.organization_postal_code_invalid'),

            'organization_country.min' => trans_message('auth.validation.organization_country_min'),
            'terms_accepted.required' => trans_message('auth.validation.terms_required'),
            'terms_accepted.accepted' => trans_message('auth.validation.terms_required'),
            'privacy_accepted.required' => trans_message('auth.validation.privacy_required'),
            'privacy_accepted.accepted' => trans_message('auth.validation.privacy_required'),
            'idempotency_key.required' => trans_message('auth.validation.idempotency_key_required'),
            'idempotency_key.*' => trans_message('auth.validation.idempotency_key_invalid'),
        ];
    }
}
