<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\UserManagement;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class StoreForemanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,NULL,id,deleted_at,NULL'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-\(\)]{7,20}$/'],
            'position' => ['nullable', 'string', 'max:255'],
            'role_slug' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/jpg', 'mimes:jpeg,png,jpg', 'max:2048'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => trans_message('user.validation_failed'),
                'errors' => $errors,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
