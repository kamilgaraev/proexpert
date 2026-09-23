<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile\Auth;

use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class MobileLogoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'installation_id' => ['sometimes', 'string', 'min:1', 'max:128'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('auth.validation_error'),
            422,
            $validator->errors(),
        ));
    }
}
