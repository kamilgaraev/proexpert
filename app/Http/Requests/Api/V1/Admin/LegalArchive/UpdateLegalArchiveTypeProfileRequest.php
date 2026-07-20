<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateLegalArchiveTypeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'schema' => ['sometimes', 'array'],
            'required_fields' => ['sometimes', 'array'],
            'required_file_roles' => ['sometimes', 'array'],
            'requires_signature' => ['sometimes', 'boolean'],
            'allowed_signature_kinds' => ['sometimes', 'array'],
            'required_signature_kinds' => ['sometimes', 'array'],
            'allowed_signature_formats' => ['sometimes', 'array'],
            'workflow_template_id' => ['nullable', 'integer', 'min:1'],
            'retention_policy' => ['nullable', 'string', 'max:191'],
            'confidentiality_level' => ['nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
