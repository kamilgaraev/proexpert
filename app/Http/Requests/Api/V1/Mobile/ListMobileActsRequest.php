<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use App\Http\Responses\MobileResponse;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ListMobileActsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return $organizationId > 0 && $this->user() instanceof User;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            'Проверьте параметры запроса.',
            422,
            $validator->errors(),
        ));
    }
}
