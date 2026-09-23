<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Http\Requests;

use App\Http\Responses\MobileResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class RegisterMobileDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'installation_id' => ['required', 'string', 'max:128'],
            'platform' => ['required', Rule::in(['android'])],
            'provider' => ['required', Rule::in(['rustore'])],
            'token' => ['required', 'string', 'min:1', 'max:4096'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('notifications.validation_error'),
            422,
            $validator->errors(),
        ));
    }
}
