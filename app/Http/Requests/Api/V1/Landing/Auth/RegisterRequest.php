<?php

namespace App\Http\Requests\Api\V1\Landing\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Определяет, авторизован ли пользователь для выполнения запроса.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Правила валидации для запроса.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            // Данные пользователя
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/' // Как минимум одна маленькая буква, одна большая и одна цифра
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^(\+7|8)[- ]?\(?[0-9]{3}\)?[- ]?[0-9]{3}[- ]?[0-9]{2}[- ]?[0-9]{2}$/' // Российский формат телефона
            ],
            'position' => 'nullable|string|max:100',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            
            // Данные организации
            'organization_name' => 'required|string|max:255|min:2',
            'organization_legal_name' => 'nullable|string|max:255|min:2',
            'organization_tax_number' => [
                'nullable',
                'string',
                'regex:/^(\d{10}|\d{12})$/'
            ],
            'organization_registration_number' => [
                'nullable',
                'string',
                'regex:/^(\d{13}|\d{15})$/'
            ],
            'organization_phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^(\+7|8)[- ]?\(?[0-9]{3}\)?[- ]?[0-9]{3}[- ]?[0-9]{2}[- ]?[0-9]{2}$/' // Российский формат телефона
            ],
            'organization_email' => 'nullable|string|email|max:255',
            'organization_address' => 'nullable|string|max:500|min:10',
            'organization_city' => 'nullable|string|max:100|min:2|regex:/^[а-яёА-ЯЁa-zA-Z\s\-\.]+$/u',
            'organization_postal_code' => [
                'nullable',
                'string',
                'regex:/^\d{6}$/'
            ],
            'organization_country' => 'nullable|string|max:100|min:2',
        ];
    }

    /**
     * Кастомные сообщения об ошибках валидации.
     *
     * @return array<string, string>
     */
    public function messages()
    {
        return [
            'name.required' => 'Имя обязательно для заполнения',
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Введите корректный email адрес',
            'email.unique' => 'Пользователь с таким email уже существует',
            'password.required' => 'Пароль обязателен для заполнения',
            'password.min' => 'Пароль должен содержать не менее 8 символов',
            'password.confirmed' => 'Пароли не совпадают',
            'password.regex' => 'Пароль должен содержать минимум одну заглавную букву, одну строчную букву и одну цифру',
            'phone.regex' => 'Некорректный формат телефона. Используйте формат +7XXXXXXXXXX или 8XXXXXXXXXX',
            
            'organization_name.required' => 'Название организации обязательно для заполнения',
            'organization_name.min' => 'Название организации должно содержать не менее 2 символов',
            'organization_legal_name.min' => 'Юридическое название должно содержать не менее 2 символов',
            
            'organization_tax_number.regex' => 'ИНН должен содержать 10 цифр для организации или 12 цифр для ИП',
            'organization_registration_number.regex' => 'ОГРН должен содержать 13 цифр для организации или 15 цифр для ИП (ОГРНИП)',
            
            'organization_phone.regex' => 'Некорректный формат телефона. Используйте формат +7XXXXXXXXXX или 8XXXXXXXXXX',
            'organization_email.email' => 'Введите корректный email адрес организации',
            
            'organization_address.min' => 'Адрес должен содержать не менее 10 символов',
            'organization_address.max' => 'Адрес не должен превышать 500 символов',
            
            'organization_city.min' => 'Название города должно содержать не менее 2 символов',
            'organization_city.regex' => 'Название города может содержать только буквы, пробелы, дефисы и точки',
            
            'organization_postal_code.regex' => 'Почтовый индекс должен содержать ровно 6 цифр',
            
            'organization_country.min' => 'Название страны должно содержать не менее 2 символов',
        ];
    }
} 