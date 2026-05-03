<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\UserManagement;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UpdateForemanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9\s\-\(\)]{7,20}$/'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar' => ['sometimes', 'nullable', 'file', 'mimetypes:image/jpeg,image/png,image/jpg', 'mimes:jpeg,png,jpg', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => trans_message('user.validation_failed'),
                'errors' => $errors,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
