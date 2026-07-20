<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\LegalArchive\LegalArchiveDictionary;
use App\Services\LegalArchive\Sources\LegalDocumentSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLegalArchiveDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['metadata', 'links', 'version_metadata'] as $key) {
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
        $organizationContext = [
            'organization_id' => (int) $this->attributes->get('current_organization_id'),
        ];

        if (! $user instanceof User) {
            return false;
        }

        $authorization = app(AuthorizationService::class);
        if (! $authorization->can($user, 'legal_archive.create', $organizationContext)) {
            return false;
        }

        return ! $this->hasFile('file') || (
            $authorization->can($user, 'legal_archive.files.upload', $organizationContext)
            && $authorization->can($user, 'legal_archive.versions.create', $organizationContext)
        );
    }

    public function rules(): array
    {
        return [
            'primary_project_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:512'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'document_type' => ['required', 'string', Rule::in(LegalArchiveDictionary::values('types'))],
            'type_profile_code' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('statuses'))],
            'direction' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('directions'))],
            'source_system' => ['nullable', 'string', 'max:64'],
            'source_type' => ['nullable', 'required_with:source_id,source_idempotency_key', 'string', Rule::in(LegalDocumentSourceType::values())],
            'source_id' => ['nullable', 'required_with:source_type,source_idempotency_key', 'integer', 'min:1'],
            'source_idempotency_key' => ['nullable', 'required_with:source_type,source_id', 'string', 'max:191'],
            'create_operation_key' => ['bail', 'required_without:source_type', 'string', 'max:191', 'regex:/\\S/u'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
            'document_date' => ['nullable', 'date'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'description' => ['nullable', 'string', 'max:5000'],
            'legal_significance_status' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('legal_significance_statuses'))],
            'edo_status' => ['nullable', 'string', 'max:64'],
            'one_c_status' => ['nullable', 'string', 'max:64'],
            'metadata' => ['nullable', 'array'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*.link_type' => ['required_with:links', 'string', Rule::in(LegalArchiveDictionary::values('link_types'))],
            'links.*.linked_type' => ['nullable', 'required_with:links.*.linked_id', 'string', Rule::in(LegalDocumentSourceType::values())],
            'links.*.linked_id' => ['nullable', 'required_with:links.*.linked_type', 'integer', 'min:1'],
            'links.*.external_key' => ['nullable', 'string', 'max:255'],
            'links.*.display_name' => ['required_with:links', 'string', 'max:255'],
            'links.*.url' => ['nullable', 'url', 'max:2000'],
            'links.*.metadata' => ['nullable', 'array'],
            'file' => ['nullable', 'file', 'max:102400'],
            'version_number' => ['nullable', 'string', 'max:64'],
            'version_label' => ['nullable', 'string', 'max:255'],
            'version_status' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('version_statuses'))],
            'version_metadata' => ['nullable', 'array'],
        ];
    }
}
