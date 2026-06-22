<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\LegalArchive\LegalArchiveDictionary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLegalArchiveVersionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $metadata = $this->input('metadata');

        if (! is_string($metadata)) {
            return;
        }

        $decoded = json_decode($metadata, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $this->merge(['metadata' => $decoded]);
        }
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && app(AuthorizationService::class)->can($user, 'legal_archive.versions.create', [
                'organization_id' => (int) $this->attributes->get('current_organization_id'),
            ])
            && app(AuthorizationService::class)->can($user, 'legal_archive.files.upload', [
                'organization_id' => (int) $this->attributes->get('current_organization_id'),
            ]);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:102400'],
            'version_number' => ['nullable', 'string', 'max:64'],
            'version_label' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('version_statuses'))],
            'make_current' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
