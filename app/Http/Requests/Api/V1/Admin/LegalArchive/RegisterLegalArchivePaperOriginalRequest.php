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
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'document_version_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'signed_at' => ['required', 'date', 'before_or_equal:now'],
            'storage_location' => ['required', 'string', 'max:2000'],
            'authority_confirmed' => ['required', 'accepted'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.kind' => ['required', 'in:manual'],
            'signers.*.name' => ['required', 'string', 'max:255'],
            'signers.*.position' => ['nullable', 'string', 'max:255'],
            'signers.*.authority_basis' => ['nullable', 'string', 'max:512'],
        ];
    }
}
