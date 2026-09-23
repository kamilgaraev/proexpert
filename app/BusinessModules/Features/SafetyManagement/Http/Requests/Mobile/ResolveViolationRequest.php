<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Requests\Mobile;

use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ResolveViolationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_comment' => ['required', 'string', 'max:1000'],
            'photos' => ['sometimes', 'array', 'max:5'],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'resolution_comment.required' => trans_message('safety_management.validation.resolution_comment_required'),
            'photos.max' => trans_message('safety_management.validation.photos_limit'),
            'photos.*.image' => trans_message('safety_management.validation.photo_invalid'),
            'photos.*.mimes' => trans_message('safety_management.validation.photo_invalid'),
            'photos.*.max' => trans_message('safety_management.validation.photo_too_large'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('safety_management.errors.validation_failed'),
            422,
            $validator->errors()->toArray(),
        ));
    }
}
