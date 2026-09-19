<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class CreateContractBuilderInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'template_id' => ['required', 'uuid'],
            'template_version' => ['required', 'integer', 'min:1'],
            'values' => ['present', 'array', 'max:500'],
            'request_key' => ['required', 'string', 'max:191'],
        ];
    }
}
