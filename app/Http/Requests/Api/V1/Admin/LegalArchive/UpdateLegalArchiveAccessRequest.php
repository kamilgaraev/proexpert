<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLegalArchiveAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'subject_kind' => ['required', 'string', Rule::in(['internal_user', 'internal_role', 'external_org', 'external_user'])],
            'subject_organization_id' => ['required', 'integer', 'min:1'],
            'subject_user_id' => ['nullable', 'integer', 'min:1'],
            'subject_role_slug' => ['nullable', 'string', 'max:191'],
            'abilities' => ['required', 'array', 'min:1', 'max:6'],
            'abilities.*' => ['string', Rule::in(['view', 'download', 'comment', 'edit', 'manage', 'sign'])],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
