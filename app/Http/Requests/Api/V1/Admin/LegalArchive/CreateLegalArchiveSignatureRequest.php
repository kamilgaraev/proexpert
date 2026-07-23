<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateLegalArchiveSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'document_version_id' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', Rule::in(['paper', 'external_electronic', 'provider_electronic'])],
            'provider' => ['nullable', 'string', 'max:191'],
            'party_id' => ['nullable', 'integer', 'min:1'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*' => ['array'],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'replaces_request_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
