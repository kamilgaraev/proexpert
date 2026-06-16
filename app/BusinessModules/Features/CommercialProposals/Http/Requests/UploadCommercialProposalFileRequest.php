<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UploadCommercialProposalFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200'],
            'version_id' => ['nullable', 'uuid'],
            'category' => ['nullable', 'string', Rule::in(['attachment', 'source', 'customer_response'])],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
