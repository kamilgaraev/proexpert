<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class ApplyContractTemplateUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'base_revision' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'target_version' => ['required', 'integer', 'min:1'],
            'comparison_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'resolution' => ['required', 'in:local,template'],
            'values' => ['sometimes', 'array', 'max:500'],
            'acknowledge_removed_values' => ['sometimes', 'boolean'],
            'request_key' => ['required', 'string', 'max:191'],
        ];
    }
}
