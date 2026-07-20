<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterLegalArchiveOriginalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'method' => ['required', 'string', Rule::in(['paper', 'external_electronic'])],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'signed_at' => ['required', 'date', 'before_or_equal:now'],
            'storage_location' => ['required_if:method,paper', 'nullable', 'string', 'max:2000'],
            'provider' => ['required_if:method,external_electronic', 'nullable', 'string', 'max:191'],
            'file' => ['required_if:method,external_electronic', 'file', 'max:20480'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.kind' => ['required', 'string'],
            'signers.*.name' => ['required', 'string', 'max:255'],
            'signers.*.user_id' => ['nullable', 'integer', 'min:1'],
            'signers.*.organization_id' => ['nullable', 'integer', 'min:1'],
            'signers.*.party_id' => ['nullable', 'integer', 'min:1'],
            'signers.*.role_slug' => ['nullable', 'string', 'max:191'],
            'signers.*.tax_number' => ['nullable', 'string', 'max:32'],
            'signers.*.position' => ['nullable', 'string', 'max:255'],
            'signers.*.party_role' => ['nullable', 'string', 'max:64'],
            'signers.*.authority_basis' => ['nullable', 'string', 'max:512'],
            'party_id' => ['nullable', 'integer', 'min:1'],
            'party_role_snapshot' => ['nullable', 'string', 'max:64'],
            'authority_confirmed' => ['sometimes', 'boolean'],
            'evidence' => ['required_if:method,external_electronic', 'nullable', 'array'],
            'provider_metadata' => ['sometimes', 'array'],
        ];
    }
}
