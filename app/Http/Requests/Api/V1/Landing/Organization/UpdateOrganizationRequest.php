<?php

namespace App\Http\Requests\Api\V1\Landing\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateOrganizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Проверяем, что пользователь авторизован и имеет роль 'organization_owner'
        // Реальная проверка роли должна быть в middleware или сервисе
        return Auth::check();
        // return Auth::check() && Auth::user()->hasRole('organization_owner'); // Пример
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Получаем ID организации из current_organization_id пользователя
        $organizationId = Auth::user()->current_organization_id ?? null;
        
        return [
            'name' => 'sometimes|required|string|max:255|min:2',
            'legal_name' => 'nullable|string|max:255|min:2',
            'tax_number' => array_filter([
                'nullable',
                'string',
                'regex:/^(\d{10}|\d{12})$/',
                $organizationId ? 'unique:organizations,tax_number,' . $organizationId : 'unique:organizations,tax_number'
            ]),
            'registration_number' => array_filter([
                'nullable',
                'string',
                'regex:/^(\d{13}|\d{15})$/',
                $organizationId ? 'unique:organizations,registration_number,' . $organizationId : 'unique:organizations,registration_number'
            ]),
            'phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^(\+7|8)[- ]?\(?[0-9]{3}\)?[- ]?[0-9]{3}[- ]?[0-9]{2}[- ]?[0-9]{2}$/'
            ],
            'email' => array_filter([
                'nullable',
                'string',
                'email',
                'max:255',
                $organizationId ? 'unique:organizations,email,' . $organizationId : 'unique:organizations,email'
            ]),
            'address' => 'nullable|string|max:500|min:10',
            'city' => 'nullable|string|max:100|min:2|regex:/^[а-яёА-ЯЁa-zA-Z\s\-\.]+$/u',
            'postal_code' => [
                'nullable',
                'string',
                'regex:/^\d{6}$/'
            ],
            'country' => 'nullable|string|max:100|min:2',
            'description' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название организации обязательно для заполнения',
            'name.min' => 'Название организации должно содержать не менее 2 символов',
            'legal_name.min' => 'Юридическое название должно содержать не менее 2 символов',
            
            'tax_number.regex' => 'ИНН должен содержать 10 цифр для организации или 12 цифр для ИП',
            'tax_number.unique' => 'Организация с таким ИНН уже существует',
            'registration_number.regex' => 'ОГРН должен содержать 13 цифр для организации или 15 цифр для ИП (ОГРНИП)',
            'registration_number.unique' => 'Организация с таким ОГРН уже существует',
            
            'phone.regex' => 'Некорректный формат телефона. Используйте формат +7XXXXXXXXXX или 8XXXXXXXXXX',
            'email.email' => 'Введите корректный email адрес',
            'email.unique' => 'Организация с таким email уже существует',
            
            'address.min' => 'Адрес должен содержать не менее 10 символов',
            'address.max' => 'Адрес не должен превышать 500 символов',
            
            'city.min' => 'Название города должно содержать не менее 2 символов',
            'city.regex' => 'Название города может содержать только буквы, пробелы, дефисы и точки',
            
            'postal_code.regex' => 'Почтовый индекс должен содержать ровно 6 цифр',
            
            'country.min' => 'Название страны должно содержать не менее 2 символов',
            'description.max' => 'Описание не должно превышать 1000 символов',
        ];
    }
} 