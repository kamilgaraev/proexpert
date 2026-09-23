<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Requests\Mobile;

use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class AssignQualityDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('quality_control.errors.validation_failed'),
            422,
            $validator->errors(),
        ));
    }
}
