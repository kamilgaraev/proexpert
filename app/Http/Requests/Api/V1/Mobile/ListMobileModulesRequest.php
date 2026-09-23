<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ListMobileModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('mobile_modules.errors.invalid_project'),
            422,
            $validator->errors(),
        ));
    }
}
