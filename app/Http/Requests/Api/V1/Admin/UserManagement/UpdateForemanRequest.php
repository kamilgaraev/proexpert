<?php

namespace App\Http\Requests\Api\V1\Admin\UserManagement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UpdateForemanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: Auth::user()->can('update_foreman', $this->route('user'))
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user'); // ID из маршрута
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Email обычно не меняют или делают это отдельным процессом
            // 'email' => ['sometimes', 'required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$userId],
            'password' => ['nullable', 'confirmed', Password::defaults()], // Пароль опционален и требует подтверждения
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9\s\-\(\)]{7,20}$/'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'], // 2MB Max
            'remove_avatar' => ['sometimes', 'boolean'],
            // Можно добавить поле для активации/деактивации пользователя в организации
            // 'is_active_in_org' => ['sometimes', 'boolean'] 
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Данные не прошли валидацию.',
                'errors' => $errors,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
} 