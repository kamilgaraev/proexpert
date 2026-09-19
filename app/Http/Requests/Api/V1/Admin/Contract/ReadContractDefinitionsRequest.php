<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class ReadContractDefinitionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'references' => ['present', 'array', 'max:500'],
            'references.*' => ['required', 'array:id,version'],
            'references.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'references.*.version' => ['required', 'integer', 'min:1'],
        ];
    }
}
