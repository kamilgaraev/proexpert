<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\LegalArchive\LegalArchiveDictionary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLegalArchiveDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['metadata', 'links'] as $key) {
            $value = $this->input($key);

            if (! is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge([$key => $decoded]);
            }
        }
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && app(AuthorizationService::class)->can($user, 'legal_archive.update', [
                'organization_id' => (int) $this->attributes->get('current_organization_id'),
            ]);
    }

    public function rules(): array
    {
        return [
            'primary_project_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['sometimes', 'required', 'string', 'max:512'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'document_type' => ['sometimes', 'required', 'string', Rule::in(LegalArchiveDictionary::values('types'))],
            'status' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('statuses'))],
            'direction' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('directions'))],
            'source_system' => ['nullable', 'string', 'max:64'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
            'document_date' => ['nullable', 'date'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'description' => ['nullable', 'string', 'max:5000'],
            'legal_significance_status' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('legal_significance_statuses'))],
            'edo_status' => ['nullable', 'string', 'max:64'],
            'one_c_status' => ['nullable', 'string', 'max:64'],
            'retention_policy' => ['nullable', 'string', 'max:128'],
            'retention_basis' => ['nullable', 'string', 'max:2000'],
            'retention_started_at' => ['nullable', 'date'],
            'retention_until' => ['nullable', 'date'],
            'legal_hold' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*.link_type' => ['required_with:links', 'string', Rule::in(LegalArchiveDictionary::values('link_types'))],
            'links.*.linked_type' => ['nullable', 'string', 'max:255'],
            'links.*.linked_id' => ['nullable', 'string', 'max:255'],
            'links.*.external_key' => ['nullable', 'string', 'max:255'],
            'links.*.display_name' => ['required_with:links', 'string', 'max:255'],
            'links.*.url' => ['nullable', 'url', 'max:2000'],
            'links.*.metadata' => ['nullable', 'array'],
        ];
    }
}
