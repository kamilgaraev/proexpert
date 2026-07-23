<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'evidence.signature_kind' => ['required_if:method,external_electronic', Rule::in(['detached_cades', 'embedded_cades', 'xml_dsig'])],
            'evidence.container_format' => ['required_if:method,external_electronic', Rule::in(['p7s', 'p7m', 'sig', 'xml'])],
            'evidence.certificate' => ['required_if:method,external_electronic', 'array:fingerprint,serial,issuer,valid_from,valid_until'],
            'evidence.certificate.fingerprint' => ['required_if:method,external_electronic', 'regex:/^[a-f0-9]{64}$/'],
            'evidence.certificate.serial' => ['required_if:method,external_electronic', 'string', 'max:128'],
            'evidence.certificate.issuer' => ['required_if:method,external_electronic', 'string', 'max:512'],
            'evidence.certificate.valid_from' => ['required_if:method,external_electronic', 'date'],
            'evidence.certificate.valid_until' => ['required_if:method,external_electronic', 'date', 'after:evidence.certificate.valid_from'],
            'evidence.authority_confirmed' => ['required_if:method,external_electronic', 'boolean'],
            'evidence.time_source' => ['required_if:method,external_electronic', Rule::in(['provider', 'trusted_timestamp', 'certificate', 'operator'])],
            'evidence.diagnostic_code' => ['required_if:method,external_electronic', 'string', 'max:128'],
            'evidence.signed_at' => ['required_if:method,external_electronic', 'date', 'before_or_equal:now'],
            'evidence.verified_at' => ['required_if:method,external_electronic', 'date', 'after_or_equal:evidence.signed_at', 'before_or_equal:now'],
            'evidence.party_role_snapshot' => ['nullable', 'string', 'max:64'],
            'evidence.signing_session_id' => ['nullable', 'string', 'max:191'],
            'evidence.client_ip_hash' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'evidence.user_agent_hash' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'provider_metadata' => ['sometimes', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('method') !== 'external_electronic') {
                return;
            }
            if ((string) $this->input('signed_at') !== (string) $this->input('evidence.signed_at')) {
                $validator->errors()->add('evidence.signed_at', trans_message('legal_archive.messages.validation_error'));
            }
        });
    }
}
