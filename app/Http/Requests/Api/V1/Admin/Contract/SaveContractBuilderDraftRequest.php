<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class SaveContractBuilderDraftRequest extends FormRequest
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
            'document' => ['required', 'array'],
            'values' => ['present', 'array', 'max:500'],
            'request_key' => ['required', 'string', 'max:191'],
            'source_refresh_hash' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'attachment_ids' => ['sometimes', 'array', 'max:50', 'list'],
            'attachment_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
