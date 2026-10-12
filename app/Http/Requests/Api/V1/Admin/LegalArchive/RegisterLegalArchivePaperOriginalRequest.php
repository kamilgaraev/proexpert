<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterLegalArchivePaperOriginalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [
            'lock_version' => ['required', 'integer', 'min:0'],
            'document_version_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'signed_at' => ['required', 'date', 'before_or_equal:now'],
            'storage_location' => ['required', 'string', 'max:2000'],
            'authority_confirmed' => ['required', 'accepted'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.kind' => ['required', 'in:manual,party'],
            'signers.*.name' => ['required', 'string', 'max:512'],
            'signers.*.organization_id' => ['nullable', 'integer', 'min:1'],
            'signers.*.party_id' => ['required_if:signers.*.kind,party', 'nullable', 'integer', 'min:1'],
            'signers.*.tax_number' => ['nullable', 'string', 'max:32'],
            'signers.*.registration_number' => ['nullable', 'string', 'max:64'],
            'signers.*.party_role' => ['required_if:signers.*.kind,party', 'nullable', 'string', 'max:64'],
            'signers.*.position' => ['nullable', 'string', 'max:255'],
            'signers.*.authority_basis' => ['nullable', 'string', 'max:512'],
        ];
        foreach ((array) $this->input('signers', []) as $index => $signer) {
            if (!is_array($signer) || ($signer['kind'] ?? null) !== 'party') $rules['signers.'.$index.'.name'] = ['required', 'string', 'max:255'];
        }
        return $rules;
    }
}
