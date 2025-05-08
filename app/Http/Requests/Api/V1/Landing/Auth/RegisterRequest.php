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
            'organization_name' => 'required|string|max:255',
            'organization_legal_name' => 'nullable|string|max:255',
            'organization_tax_number' => 'nullable|string|max:50',
            'organization_registration_number' => 'nullable|string|max:50',
            'organization_phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^(\+7|8)[- ]?\(?[0-9]{3}\)?[- ]?[0-9]{3}[- ]?[0-9]{2}[- ]?[0-9]{2}$/' // Российский формат телефона
            ],
            'organization_email' => 'nullable|string|email|max:255',
            'organization_address' => 'nullable|string|max:255',
            'organization_city' => 'nullable|string|max:100',
            'organization_postal_code' => 'nullable|string|max:20',
            'organization_country' => 'nullable|string|max:100',
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
            'organization_phone.regex' => 'Некорректный формат телефона. Используйте формат +7XXXXXXXXXX или 8XXXXXXXXXX',
            'organization_name.required' => 'Название организации обязательно для заполнения',
        ];
    }
} 