<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Requests\Mobile;

use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class StoreViolationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'string', Rule::in(['minor', 'major', 'high', 'critical'])],
            'location_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'photos' => ['sometimes', 'array', 'max:5'],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required' => trans_message('safety_management.validation.project_required'),
            'project_id.integer' => trans_message('safety_management.validation.project_invalid'),
            'title.required' => trans_message('safety_management.validation.title_required'),
            'severity.required' => trans_message('safety_management.validation.severity_required'),
            'severity.in' => trans_message('safety_management.validation.severity_invalid'),
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
