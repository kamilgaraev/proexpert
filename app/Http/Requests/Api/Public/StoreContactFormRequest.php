<?php

namespace App\Http\Requests\Api\Public;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|min:2',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20|regex:/^[\+]?[0-9\-\(\)\s]+$/',
            'company' => 'nullable|string|max:255',
            'subject' => 'required|string|max:255|min:5',
            'message' => 'required|string|max:5000|min:10',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Поле "Имя" обязательно для заполнения.',
            'name.min' => 'Имя должно содержать не менее 2 символов.',
            'name.max' => 'Имя не должно превышать 255 символов.',
            
            'email.required' => 'Поле "Email" обязательно для заполнения.',
            'email.email' => 'Введите корректный email адрес.',
            'email.max' => 'Email не должен превышать 255 символов.',
            
            'phone.regex' => 'Введите корректный номер телефона.',
            'phone.max' => 'Номер телефона не должен превышать 20 символов.',
            
            'company.max' => 'Название компании не должно превышать 255 символов.',
            
            'subject.required' => 'Поле "Тема" обязательно для заполнения.',
            'subject.min' => 'Тема должна содержать не менее 5 символов.',
            'subject.max' => 'Тема не должна превышать 255 символов.',
            
            'message.required' => 'Поле "Сообщение" обязательно для заполнения.',
            'message.min' => 'Сообщение должно содержать не менее 10 символов.',
            'message.max' => 'Сообщение не должно превышать 5000 символов.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'имя',
            'email' => 'email',
            'phone' => 'телефон',
            'company' => 'компания',
            'subject' => 'тема',
            'message' => 'сообщение',
        ];
    }
}
