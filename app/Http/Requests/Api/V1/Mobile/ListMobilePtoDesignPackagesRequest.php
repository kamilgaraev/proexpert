<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use App\BusinessModules\Features\DesignManagement\Enums\DesignPackageStatusEnum;
use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class ListMobilePtoDesignPackagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(array_map(static fn (DesignPackageStatusEnum $status): string => $status->value, DesignPackageStatusEnum::cases()))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('errors.validation_failed'),
            422,
            $validator->errors(),
        ));
    }
}
